<?php

namespace Restruct\Silverstripe\SEO\Migration\Mappers;

use Restruct\Silverstripe\SEO\Migration\MigrationRun;
use Restruct\Silverstripe\SEO\Migration\SeoMigrationMapper;
use SilverStripe\Assets\File;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Control\Controller;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\ORM\DB;
use SilverStripe\Versioned\Versioned;

/**
 * bigfork/silverstripe-simpleseo (5.x) with kinglozzer/metatitle. MetaTitle is the same column. The canonical is
 * a silverstripe/linkfield Link (has_one CanonicalLink on SiteTree); it is resolved per stage, from that stage's
 * Link tables, to the URL this module's CanonicalURL takes:
 * - a page: its link, root-relative (with the link's query string and anchor), so the URL does not carry the
 *   host name of wherever the task happened to run; the module makes it absolute on output;
 * - a file: its URL;
 * - an external link: the URL as entered;
 * - a link to the page itself: nothing to copy, as that is the default canonical;
 * - other link types (email, phone) and missing links: invalid.
 */
class SimpleSeoMapper implements SeoMigrationMapper
{
    use Injectable;

    protected const LINK_MODELS = 'SilverStripe\\LinkField\\Models\\';

    public function getTitle(): string
    {
        return 'bigfork/silverstripe-simpleseo + kinglozzer/metatitle';
    }

    public function migrate(MigrationRun $run): void
    {
        $run->copyColumn(['SiteTree'], 'MetaTitle', 'SiteTree', 'MetaTitle');
        if (!$run->hasTable('LinkField_Link') && $run->locateColumn(['SiteTree'], 'CanonicalLinkID')) {
            $run->note(_t(__CLASS__ . '.NoLinkField', 'LinkField_Link not found (silverstripe/linkfield removed?): the canonical links cannot be resolved.'));
        }
        $run->copyColumn(
            ['SiteTree'],
            'CanonicalLinkID',
            'SiteTree',
            'CanonicalURL',
            function (mixed $linkID, ?string $stage, int $pageID) use ($run) {
                $url = $this->resolve($run, (int) $linkID, $stage, $pageID);
                # A resolved URL still has to be one this module accepts (e.g. not a "javascript:" external link)
                return is_string($url) ? $run->canonicalTransform()($url) : $url;
            }
        );
        $run->note(_t(__CLASS__ . '.ExtraMeta', 'ExtraMeta ("Custom meta tags") is core\'s own field: nothing to do.'));
    }

    /**
     * @return string|false|null the URL, {@see MigrationRun::NOTHING} for a link to the page itself, or null
     */
    protected function resolve(MigrationRun $run, int $linkID, ?string $stage, int $pageID): string|false|null
    {
        $suffix = $stage === MigrationRun::STAGE_LIVE ? '_Live' : '';
        $link = $this->row($run, 'LinkField_Link' . $suffix, $linkID);
        if (!$link) {
            return null;
        }
        $type = preg_replace('/^' . preg_quote(self::LINK_MODELS, '/') . '/', '', (string) $link['ClassName']);
        $details = $this->row($run, 'LinkField_' . $type . $suffix, $linkID);
        if (!$details) {
            return null;
        }
        $mode = $stage === MigrationRun::STAGE_LIVE ? Versioned::LIVE : Versioned::DRAFT;

        return $this->resolveDetails($type, $details, $mode, $pageID);
    }

    /**
     * @param array<string, mixed> $details the row of the link type's own table
     */
    protected function resolveDetails(string $type, array $details, string $mode, int $pageID): string|false|null
    {
        switch ($type) {
            case 'ExternalLink':
                $url = trim((string) $details['ExternalUrl']);
                return $url !== '' ? $url : null;
            case 'SiteTreeLink':
                $targetID = (int) $details['PageID'];
                if ($targetID === $pageID) {
                    return MigrationRun::NOTHING;
                }
                $page = Versioned::get_by_stage(SiteTree::class, $mode)->byID($targetID);
                if (!$page) {
                    return null;
                }
                $anchor = $details['Anchor'] ? '#' . $details['Anchor'] : '';
                $query = $details['QueryString'] ? '?' . $details['QueryString'] : '';
                # The same composition as linkfield's SiteTreeLink::getURL(), without the "?stage=Stage" that
                # versioned adds to the link of a record read from the draft stage
                return Controller::join_links($this->withoutReadingMode($page->Link()), $anchor, $query);
            case 'FileLink':
                $file = Versioned::get_by_stage(File::class, $mode)->byID((int) $details['FileID']);
                return $file ? ((string) $file->getURL() ?: null) : null;
        }
        return null;
    }

    /**
     * A link without the query parameters versioned adds for a reading mode (see ReadingMode::toQueryString()).
     */
    protected function withoutReadingMode(string $link): string
    {
        $parts = explode('?', $link, 2);
        if (count($parts) < 2) {
            return $link;
        }
        [$query, $fragment] = array_pad(explode('#', $parts[1], 2), 2, null);
        parse_str($query, $params);
        unset($params['stage'], $params['archiveDate']);
        $link = $parts[0] . ($params ? '?' . http_build_query($params) : '');
        return $fragment !== null ? $link . '#' . $fragment : $link;
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function row(MigrationRun $run, string $table, int $id): ?array
    {
        if (!$run->hasTable($table)) {
            return null;
        }
        $row = DB::prepared_query(sprintf('SELECT * FROM "%s" WHERE "ID" = ?', $table), [$id])->record();
        return $row ?: null;
    }
}
