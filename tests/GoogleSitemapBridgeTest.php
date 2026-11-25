<?php

namespace Restruct\Silverstripe\SEO\Tests;

use Restruct\Silverstripe\SEO\Tests\Stub\SeoTestPage;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Dev\SapphireTest;
use Wilr\GoogleSitemaps\GoogleSitemap;

/**
 * The bridge to wilr/silverstripe-googlesitemaps: a noindex page is left out of sitemap.xml.
 *
 * wilr's module is a suggestion, not a requirement, so without it the test is skipped; it is in require-dev,
 * so CI always runs it.
 */
class GoogleSitemapBridgeTest extends SapphireTest
{
    protected $usesDatabase = true;

    protected static $extra_dataobjects = [
        SeoTestPage::class,
    ];

    protected function setUp(): void
    {
        if (!class_exists(GoogleSitemap::class)) {
            $this->markTestSkipped('wilr/silverstripe-googlesitemaps is not installed, so there is no sitemap to bridge to');
        }
        parent::setUp();
    }

    private function publishedPage(string $segment, bool $noIndex): SeoTestPage
    {
        $page = SeoTestPage::create(['Title' => $segment, 'URLSegment' => $segment, 'MetaRobotsNoIndex' => $noIndex]);
        $page->write();
        $page->publishRecursive();
        return $page;
    }

    public function testNoIndexPageIsExcluded(): void
    {
        $indexed = $this->publishedPage('indexed', false);
        $hidden = $this->publishedPage('hidden', true);

        $this->assertTrue((bool) $indexed->canIncludeInGoogleSitemap());
        $this->assertFalse((bool) $hidden->canIncludeInGoogleSitemap());
    }

    public function testNoIndexPageIsMissingFromTheSitemapItems(): void
    {
        $this->publishedPage('indexed', false);
        $this->publishedPage('hidden', true);

        $segments = GoogleSitemap::create()->getItems(SiteTree::class)->column('URLSegment');
        $this->assertContains('indexed', $segments);
        $this->assertNotContains('hidden', $segments);
    }
}
