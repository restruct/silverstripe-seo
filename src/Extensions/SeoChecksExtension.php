<?php

namespace Restruct\Silverstripe\SEO\Extensions;

use Restruct\Silverstripe\RenderedContentExtractor\PageEligibility;
use Restruct\Silverstripe\SEO\Checks\LinkGraph;
use Restruct\Silverstripe\SEO\Forms\SeoChecksField;
use Restruct\Silverstripe\SEO\Forms\SeoSnippetPreviewField;
use Restruct\Silverstripe\SEO\Model\SeoCheckIgnore;
use Restruct\Silverstripe\SEO\Model\SeoCheckResult;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Core\Extension;
use SilverStripe\Forms\CompositeField;
use SilverStripe\Forms\FieldList;

/**
 * The SEO checks on a page: the panel and the search result preview in the page's "Search engines and sharing"
 * section, and the upkeep of what the checks store.
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
        /** @var CompositeField|null $metadata */
        # Core's Metadata toggle, or the module's fallback group when a page class removed it
        $metadata = $fields->fieldByName('Root.Main.Metadata') ?? $fields->fieldByName('Root.SEO.SeoFallbackGroup');
        if (!$metadata instanceof CompositeField) {
            return;
        }

        $children = $metadata->getChildren();
        # Only for members who may edit the page: the stored results come from the render of whoever ran the
        # check last, and the endpoint refuses everyone else anyway
        if ($page->canEdit()) {
            $children->unshift(SeoChecksField::create(
                'SeoChecks',
                _t(__CLASS__ . '.ChecksTitle', 'SEO checks'),
                $page
            ));
        }
        $preview = SeoSnippetPreviewField::create(
            'SeoSnippetPreview',
            _t(__CLASS__ . '.PreviewTitle', 'Search result preview'),
            $page
        );
        if ($children->dataFieldByName('MetaDescription')) {
            $children->insertAfter('MetaDescription', $preview);
        } else {
            $children->push($preview);
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
