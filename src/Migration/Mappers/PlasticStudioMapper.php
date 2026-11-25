<?php

namespace Restruct\Silverstripe\SEO\Migration\Mappers;

use Restruct\Silverstripe\SEO\Migration\MigrationResult;
use Restruct\Silverstripe\SEO\Migration\MigrationRun;
use Restruct\Silverstripe\SEO\Migration\SeoMigrationMapper;
use SilverStripe\Core\Injector\Injectable;

/**
 * plasticstudio/silverstripe-seo (6.x), and the modules that share its schema (cyber-duck/silverstripe-seo and
 * its forks). Its page extension is applied to Page, so its columns are on the Page table.
 *
 * It has no Open Graph or Twitter text columns of its own (only og:type, og:locale and the card type), so the
 * sharing description is left to fall back to the meta description.
 */
class PlasticStudioMapper implements SeoMigrationMapper
{
    use Injectable;

    protected const PAGE = ['Page', 'SiteTree'];

    /**
     * Robots directives in its "noindex,nofollow" string -> this module's flags.
     */
    protected const ROBOTS = [
        'MetaRobotsNoIndex' => ['noindex', 'none'],
        'MetaRobotsNoFollow' => ['nofollow', 'none'],
        'MetaRobotsNoSnippet' => ['nosnippet'],
        'MetaRobotsNoCache' => ['noarchive', 'nocache'],
    ];

    public function getTitle(): string
    {
        return 'plasticstudio/silverstripe-seo';
    }

    public function migrate(MigrationRun $run): void
    {
        # Its MetaTitle is Varchar(512) (Text in cyber-duck's), this module's Varchar(255)
        $run->copyColumn(self::PAGE, 'MetaTitle', 'SiteTree', 'MetaTitle', $run->maxLengthTransform(255));
        $run->copyColumn(self::PAGE, 'Canonical', 'SiteTree', 'CanonicalURL', $run->canonicalTransform());
        foreach (self::ROBOTS as $target => $directives) {
            $run->copyColumn(self::PAGE, 'Robots', 'SiteTree', $target, $this->robotsTransform($directives), true);
        }
        $run->copyColumn(self::PAGE, 'SocialImageID', 'SiteTree', 'OGImageID', $run->imageTransform(), true);

        $sitemap = 'wilr/silverstripe-googlesitemaps has its own Priority and ChangeFrequency per page';
        foreach (['Priority', 'ChangeFrequency'] as $column) {
            $run->reportColumn(self::PAGE, $column, MigrationResult::REPORTED, $sitemap);
        }
        foreach (['SitemapHide', 'XMLSitemapHide'] as $column) {
            $run->reportColumn(self::PAGE, $column, MigrationResult::REPORTED, 'noindex pages are left out of the sitemap');
        }
        $run->reportColumn(self::PAGE, 'HideSocial', MigrationResult::REPORTED, 'to leave out Open Graph site-wide set seo_output_open_graph: false');
        $run->reportColumn(self::PAGE, 'OGtype', MigrationResult::REPORTED, 'og:type is set per page class (seo_og_type)');
        $run->reportColumn(self::PAGE, 'OGlocale', MigrationResult::DROPPED, 'og:locale follows the page locale');
        $run->reportColumn(self::PAGE, 'TwitterCard', MigrationResult::REPORTED, 'the card type is site-wide (seo_twitter_card)');
        $run->reportColumn(self::PAGE, 'ManualSchema', MigrationResult::REPORTED, 'structured data is mapped from records in YAML (schema_map)');
        $run->reportColumn(self::PAGE, 'MetaTitleLastEdited', MigrationResult::DROPPED);
        $run->reportColumn(self::PAGE, 'MetaDescriptionLastEdited', MigrationResult::DROPPED);
        if ($run->hasTable('SeoHeadTag')) {
            $run->reportCount(
                'SeoHeadTag',
                MigrationResult::REPORTED,
                count($run->rows('SeoHeadTag')),
                'extra head tags: move them to core\'s ExtraMeta ("Custom meta tags") by hand'
            );
        }

        $run->copyColumn(['SiteConfig'], 'DefaultSocialImageID', 'SiteConfig', 'SeoDefaultImageID', $run->imageTransform(), true);
        $run->copyColumn(['SiteConfig'], 'SchemaOrganisationImageID', 'SiteConfig', 'SeoLogoID', $run->imageTransform(), true);
        $run->reportColumn(['SiteConfig'], 'SchemaOrganisationName', MigrationResult::REPORTED, 'the Organization node takes SiteConfig.Title');
        foreach (['Address', 'Suburb', 'State', 'Postcode', 'Country', 'Lat', 'Lng', 'Phone'] as $column) {
            $run->reportColumn(['SiteConfig'], $column, MigrationResult::REPORTED, 'map it in SiteConfig\'s schema_map (YAML)');
        }
        foreach (['OGSiteName', 'TwitterHandle', 'CreatorTwitterHandle', 'FacebookAppID', 'UseTitleAsMetaTitle'] as $column) {
            $run->reportColumn(['SiteConfig'], $column, MigrationResult::DROPPED);
        }
    }

    /**
     * 1 when the robots string holds one of the directives, otherwise nothing to copy (a flag is never cleared).
     *
     * @param string[] $directives
     */
    protected function robotsTransform(array $directives): callable
    {
        return function (mixed $value) use ($directives): int|false {
            $tokens = array_map('trim', explode(',', strtolower((string) $value)));
            return array_intersect($tokens, $directives) ? 1 : MigrationRun::NOTHING;
        };
    }
}
