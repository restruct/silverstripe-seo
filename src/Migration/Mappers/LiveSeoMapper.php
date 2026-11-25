<?php

namespace Restruct\Silverstripe\SEO\Migration\Mappers;

use Restruct\Silverstripe\SEO\Migration\MigrationResult;
use Restruct\Silverstripe\SEO\Migration\MigrationRun;
use Restruct\Silverstripe\SEO\Migration\SeoMigrationMapper;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\ORM\DB;

/**
 * The older liveseo module (global-namespace classes, on SiteTree and SiteConfig).
 *
 * Its MetaTitle, the four MetaRobots* flags and SEOFBdescription are this module's own columns, so they need no
 * copy. Its StructuredData tables are reported in detail; the one site organisation's logo and profiles on
 * other sites go to SiteConfig, the rest has no column here (it can be mapped in YAML, see the README).
 */
class LiveSeoMapper implements SeoMigrationMapper
{
    use Injectable;

    protected const PAGE = ['SiteTree'];

    /**
     * StructuredData classes that are the site organisation (or a branch of it).
     */
    protected const ORGANISATION_TYPES = ['Organization', 'LocalBusiness'];

    public function getTitle(): string
    {
        return 'the older liveseo module';
    }

    public function migrate(MigrationRun $run): void
    {
        foreach (['MetaTitle', 'MetaRobotsNoIndex', 'MetaRobotsNoFollow', 'MetaRobotsNoSnippet', 'MetaRobotsNoCache', 'SEOFBdescription'] as $column) {
            $run->copyColumn(self::PAGE, $column, 'SiteTree', $column);
        }
        foreach (['MetaKeywords', 'SEOPageSubject', 'SEOPageScore'] as $column) {
            $run->reportColumn(self::PAGE, $column, MigrationResult::DROPPED);
        }
        foreach (['SEOFBPublisherlink', 'SEOFBAuthorlink', 'SEOGplusAuthorlink', 'SEOGplusPublisherlink'] as $column) {
            $run->reportColumn(self::PAGE, $column, MigrationResult::DROPPED, 'no longer read by these sites');
        }
        $run->reportColumn(
            ['SiteConfig'],
            'SEOTitleTemplate',
            MigrationResult::DROPPED,
            'the title is the meta title (or page title), the separator and SiteConfig.SeoTitleSuffix or the site name'
        );

        $this->migrateStructuredData($run);
    }

    protected function migrateStructuredData(MigrationRun $run): void
    {
        if (!$run->hasTable('StructuredDataThing')) {
            $run->note('StructuredData tables not found: no structured data to migrate.');
            return;
        }

        $things = [];
        $perType = [];
        foreach ($run->rows('StructuredDataThing') as $row) {
            $type = $this->typeOf((string) $row['ClassName']);
            $things[(int) $row['ID']] = $row + ['_type' => $type];
            $perType[$type] = ($perType[$type] ?? 0) + 1;
        }
        $summary = [];
        foreach ($perType as $type => $count) {
            $summary[] = "{$type} {$count}";
        }
        $run->info(sprintf('StructuredData: %d rows: %s', count($things), implode(', ', $summary) ?: 'none'));

        $pages = 0;
        if ($run->hasTable('SiteTree_StructuredDataItems')) {
            $pages = (int) DB::query('SELECT COUNT(DISTINCT "SiteTreeID") FROM "SiteTree_StructuredDataItems"')->value();
        }
        $run->info(sprintf('StructuredData attached to %d pages', $pages));

        foreach (['Rating', 'AggregateRating'] as $type) {
            if (isset($perType[$type])) {
                $run->reportCount($type, MigrationResult::DROPPED, $perType[$type], 'review markup for an own organisation is not shown by Google');
            }
        }
        foreach (['OpeningHoursSpecification'] as $type) {
            if (isset($perType[$type])) {
                $run->reportCount($type, MigrationResult::REPORTED, $perType[$type], 'map it in YAML (schema_map) if it is still wanted');
            }
        }

        $this->migrateOrganisation($run, $things);

        $run->warn(
            'a site\'s live Organization JSON-LD may come from a hard-coded template rather than these tables: '
            . 'compare the page source with the rows before trusting this migration.'
        );
    }

    /**
     * The site organisation: an Organization or LocalBusiness that is not a department or location of another.
     *
     * @param array<int, array<string, mixed>> $things
     */
    protected function migrateOrganisation(MigrationRun $run, array $things): void
    {
        $children = [];
        foreach (['department', 'location'] as $relation) {
            foreach ($run->rows('StructuredDataOrganization_' . $relation) as $row) {
                $children[(int) $row['ChildID']] = $relation;
            }
        }
        $organisations = array_filter(
            $things,
            fn (array $thing) => in_array($thing['_type'], self::ORGANISATION_TYPES, true) && !isset($children[$thing['ID']])
        );
        if (!$organisations) {
            $run->note('StructuredData: no site organisation found, nothing to copy to SiteConfig.');
            return;
        }
        if (count($organisations) > 1) {
            $names = array_map(fn (array $thing) => '"' . ($thing['name'] ?: $thing['DataItemHandle']) . '"', $organisations);
            $run->warn(sprintf(
                'StructuredData has %d top-level organisations (%s): nothing copied to SiteConfig; set its logo and '
                . 'profiles by hand.',
                count($organisations),
                implode(', ', $names)
            ));
            return;
        }

        $organisation = reset($organisations);
        $id = (int) $organisation['ID'];
        $siteConfigs = $run->rows('SiteConfig');
        if (count($siteConfigs) !== 1) {
            $run->warn(sprintf(
                'there are %d SiteConfig rows (subsites?): the organisation\'s logo and profiles are not copied; '
                . 'set them by hand on the right one.',
                count($siteConfigs)
            ));
            return;
        }
        $siteConfig = reset($siteConfigs);
        $siteConfigID = (int) $siteConfig['ID'];

        $name = trim((string) $organisation['name']);
        if ($name !== '') {
            $run->note(sprintf(
                'Organization name "%s": the Organization node takes SiteConfig.Title ("%s"); change that title if '
                . 'the two should be the same.',
                $name,
                (string) ($siteConfig['Title'] ?? '')
            ));
        }

        $logos = $run->rows('StructuredDataOrganization_logo', '"StructuredDataOrganizationID" = ?', [$id]);
        if ($logos) {
            $logoID = (int) $logos[0]['ImageID'];
            $run->copyValue(
                'Organization logo -> SiteConfig.SeoLogoID',
                'SiteConfig',
                'SeoLogoID',
                $siteConfigID,
                $run->fileExists($logoID) ? $logoID : null,
                true
            );
        }

        $profiles = $this->multiValue($organisation['sameAs'] ?? null);
        if ($profiles) {
            $run->copyValue(
                'Organization sameAs -> SiteConfig.SeoSameAs',
                'SiteConfig',
                'SeoSameAs',
                $siteConfigID,
                implode("\n", $profiles)
            );
        }

        # No SiteConfig fields here for these: a project adds its own and maps them in YAML
        $hint = 'add a field for it and map it in SiteConfig\'s schema_map (YAML)';
        $details = $run->rows('StructuredDataOrganization', '"ID" = ?', [$id])[0] ?? [];
        foreach (['telephone', 'faxNumber', 'email'] as $field) {
            if (trim((string) ($details[$field] ?? '')) !== '') {
                $run->reportCount('Organization ' . $field, MigrationResult::REPORTED, 1, $hint);
            }
        }
        $relations = [
            'address' => 'StructuredDataPostalAddressID',
            'contactPoint' => 'StructuredDataContactPointID',
            'department' => 'ChildID',
            'location' => 'ChildID',
        ];
        foreach (array_keys($relations) as $relation) {
            $count = count($run->rows('StructuredDataOrganization_' . $relation, '"StructuredDataOrganizationID" = ?', [$id]));
            if ($count) {
                $run->reportCount('Organization ' . $relation, MigrationResult::REPORTED, $count, $hint);
            }
        }
    }

    /**
     * A MultiValueField column: a serialised PHP array, or JSON in newer versions of that module.
     *
     * @return string[]
     */
    protected function multiValue(mixed $stored): array
    {
        $stored = trim((string) $stored);
        if ($stored === '') {
            return [];
        }
        if (str_starts_with($stored, '[') || str_starts_with($stored, '{')) {
            $values = json_decode($stored, true);
        } else {
            # Never instantiate objects from stored data
            $values = @unserialize($stored, ['allowed_classes' => false]);
        }
        if (!is_array($values)) {
            return [];
        }
        $values = array_map(fn ($value) => is_scalar($value) ? trim((string) $value) : '', array_values($values));
        return array_values(array_filter($values, fn (string $value) => $value !== ''));
    }

    /**
     * "StructuredDataOrganization" (or a namespaced variant) -> "Organization".
     */
    protected function typeOf(string $className): string
    {
        $short = substr($className, (int) strrpos('\\' . $className, '\\'));
        return preg_replace('/^StructuredData/', '', $short) ?: $short;
    }
}
