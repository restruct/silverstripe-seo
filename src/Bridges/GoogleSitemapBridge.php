<?php

namespace Restruct\Silverstripe\SEO\Bridges;

use Restruct\Silverstripe\SEO\Extensions\SeoSiteTreeExtension;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Core\Extension;

/**
 * Leaves noindex pages out of wilr/silverstripe-googlesitemaps' sitemap.xml. Applied only when that module is
 * installed (see _config/googlesitemaps.yml).
 *
 * wilr's `canIncludeInGoogleSitemap()` collects the return values of `alterCanIncludeInGoogleSitemap` from every
 * extension and takes the lowest, so returning false here excludes the page and returning true leaves the
 * decision to the other checks (checked against wilr/silverstripe-googlesitemaps 4.1,
 * `GoogleSitemapExtension::canIncludeInGoogleSitemap()`).
 *
 * @extends Extension<SiteTree>
 */
class GoogleSitemapBridge extends Extension
{
    /**
     * @param bool $can The result of wilr's own checks so far
     */
    protected function alterCanIncludeInGoogleSitemap($can): bool
    {
        $page = $this->getOwner();
        if (!$page->hasExtension(SeoSiteTreeExtension::class)) {
            return (bool) $can;
        }
        # A sitemap lists what should be indexed; a noindex page in it sends crawlers a contradiction
        return (bool) $can && !in_array('noindex', $page->SeoRobotsDirectives(), true);
    }
}
