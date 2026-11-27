<?php

namespace Restruct\Silverstripe\SEO\Extensions;

use Restruct\Silverstripe\RenderedContentExtractor\PageEligibility;
use Restruct\Silverstripe\SEO\Checks\LinkGraph;
use Restruct\Silverstripe\SEO\Forms\SeoChecksField;
use Restruct\Silverstripe\SEO\Forms\SeoSnippetPreviewField;
use Restruct\Silverstripe\SEO\Model\SeoCheckIgnore;
use Restruct\Silverstripe\SEO\Model\SeoCheckResult;
use SilverStripe\CMS\Model\RedirectorPage;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Core\Extension;
use SilverStripe\Forms\FieldList;

/**
 * The SEO checks on a page: the panel and the search result preview at the top of the page's SEO tab
 * ({@see SeoSiteTreeExtension::$seo_tab}), and the upkeep of what the checks store.
 *
 * - After a write the stored result is marked stale. Nothing is rendered during the write: the panel sees the
 *   stale flag on the next form load (the save response included) and the browser asks the CMS endpoint for a
 *   fresh check in a request of its own. A save that only touched blocks does not write the page, so the script
 *   also re-checks after every form submit.
 * - The site's link graph (for the orphan check) is cleared on write, publish, unpublish and delete.
 * - When the page is gone from the draft stage (archived), its results and ignores go with it.
 *
 * @extends Extension<SiteTree>
 */
class SeoChecksExtension extends Extension
{
    protected function updateCMSFields(FieldList $fields): void
    {
        $page = $this->getOwner();
        # Nothing to check before the first save (no URL, nothing to render), and not on page types the checks
        # leave alone (redirectors, virtual pages, error pages)
        if (!$page->isInDB() || !PageEligibility::singleton()->isEligible($page, 'seo')) {
            return;
        }
        # A redirector page gets no SEO fields, so no tab either (it is never eligible; this keeps it so)
        if ($page instanceof RedirectorPage) {
            return;
        }
        # The page's SEO tab, made here when this extension runs before SeoSiteTreeExtension
        $children = SeoSiteTreeExtension::findOrMakeSeoTab($fields, $page)->getChildren();

        # At the top of the tab, before the fields: the preview, and above it the checks panel. Unshifted in
        # reverse, so the order holds whether the fields are already on the tab or are added after
        $children->unshift(SeoSnippetPreviewField::create(
            'SeoSnippetPreview',
            _t(__CLASS__ . '.PreviewTitle', 'Search result preview'),
            $page
        ));
        # Only for members who may edit the page: the stored results come from the render of whoever ran the
        # check last, and the endpoint refuses everyone else anyway
        if ($page->canEdit()) {
            $children->unshift(SeoChecksField::create(
                'SeoChecks',
                _t(__CLASS__ . '.ChecksTitle', 'SEO checks'),
                $page
            ));
        }
    }

    protected function onAfterWrite(): void
    {
        $page = $this->getOwner();
        SeoCheckResult::markStale($page);
        LinkGraph::singleton()->clear();
    }

    protected function onAfterPublish(): void
    {
        LinkGraph::singleton()->clear();
    }

    protected function onAfterUnpublish(): void
    {
        LinkGraph::singleton()->clear();
    }

    protected function onAfterDelete(): void
    {
        $page = $this->getOwner();
        LinkGraph::singleton()->clear();
        # Deleted from live only (unpublished): the draft and its results stay
        if ($page->isOnDraft()) {
            return;
        }
        foreach (SeoCheckResult::get()->filter('PageID', (int) $page->ID) as $result) {
            $result->delete();
        }
        foreach (SeoCheckIgnore::get()->filter('PageID', (int) $page->ID) as $ignore) {
            $ignore->delete();
        }
    }
}
