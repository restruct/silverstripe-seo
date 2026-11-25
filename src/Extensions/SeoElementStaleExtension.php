<?php

namespace Restruct\Silverstripe\SEO\Extensions;

use Restruct\Silverstripe\SEO\Model\SeoCheckResult;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Core\Extension;
use SilverStripe\ORM\DataObject;

/**
 * Applied to Elemental's BaseElement (only when Elemental is installed): a block that is written, published,
 * unpublished or deleted marks the stored SEO check results of its page stale. A save that only changed blocks
 * does not write the page, so the page's own hook never fires for it.
 *
 * @extends Extension<DataObject>
 */
class SeoElementStaleExtension extends Extension
{
    protected function onAfterWrite(): void
    {
        $this->markPageStale();
    }

    protected function onAfterPublish(): void
    {
        $this->markPageStale();
    }

    protected function onAfterUnpublish(): void
    {
        $this->markPageStale();
    }

    protected function onAfterDelete(): void
    {
        $this->markPageStale();
    }

    protected function markPageStale(): void
    {
        $owner = $this->getOwner();
        # The top page, also for a block nested in another block (BaseElement::getPage() walks up)
        $page = $owner->hasMethod('getPage') ? $owner->getPage() : null;
        if ($page instanceof SiteTree && $page->isInDB()) {
            SeoCheckResult::markStale($page);
        }
    }
}
