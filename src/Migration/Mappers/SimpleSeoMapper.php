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
 * - a page without anchor or query string: the page itself, as this module's CanonicalPage (picked in the page
 *   tree), so the canonical follows the page when it is renamed or moved;
 * - a page with an anchor or query string: its link, root-relative (with the link's query string and anchor), so
 *   the URL does not carry the host name of wherever the task happened to run; the module makes it absolute on
 *   output;
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
                # A plain page link goes to CanonicalPage below, not to the URL as well
                if ($this->plainPageLink($run, (int) $linkID, $stage) !== null) {
                    return MigrationRun::NOTHING;
                }
                $url = $this->resolve($run, (int) $linkID, $stage, $pageID);
                # A resolved URL still has to be one this module accepts (e.g. not a "javascript:" external link)
                return is_string($url) ? $run->canonicalTransform()($url) : $url;
            }
        );
        $run->copyColumn(
            ['SiteTree'],
            'CanonicalLinkID',
            'SiteTree',
            'CanonicalPageID',
            function (mixed $linkID, ?string $stage, int $pageID) use ($run) {
                $targetID = $this->plainPageLink($run, (int) $linkID, $stage);
                if ($targetID === null || $targetID === $pageID) {
                    # Not a plain page link (copied as a URL above), or the page itself: the default canonical
                    return MigrationRun::NOTHING;
                }
                $mode = $stage === MigrationRun::STAGE_LIVE ? Versioned::LIVE : Versioned::DRAFT;
                # A page that is not there in this stage is an invalid link, as for the URL
                return Versioned::get_by_stage(SiteTree::class, $mode)->byID($targetID) ? $targetID : null;
            },
            true
        );
        $run->note(_t(__CLASS__ . '.ExtraMeta', 'ExtraMeta ("Custom meta tags") is core\'s own field: nothing to do.'));
    }

    /**
     * The target page ID of a SiteTreeLink without anchor or query string, in that stage's link tables; null for
     * any other link (or none).
     */
    protected function plainPageLink(MigrationRun $run, int $linkID, ?string $stage): ?int
    {
        $suffix = $stage === MigrationRun::STAGE_LIVE ? '_Live' : '';
        $link = $this->row($run, 'LinkField_Link' . $suffix, $linkID);
        if (!$link || (string) $link['ClassName'] !== self::LINK_MODELS . 'SiteTreeLink') {
            return null;
        }
        $details = $this->row($run, 'LinkField_SiteTreeLink' . $suffix, $linkID);
        if (!$details || (int) $details['PageID'] <= 0 || (string) $details['Anchor'] !== '' || (string) $details['QueryString'] !== '') {
            return null;
        }
        return (int) $details['PageID'];
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
