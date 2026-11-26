<?php

namespace Restruct\Silverstripe\SEO\Tests;

use Restruct\Silverstripe\SEO\Forms\SeoSnippetPreviewField;
use Restruct\Silverstripe\SEO\Tests\Stub\Checks\SeoCheckTestBlock;
use Restruct\Silverstripe\SEO\Tests\Stub\Checks\SeoCheckTestPage;
use Restruct\Silverstripe\SEO\Tests\Stub\SeoGetterTestPage;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Dev\FunctionalTest;
use SilverStripe\SiteConfig\SiteConfig;
use SilverStripe\View\SSViewer;
use Wilr\GoogleSitemaps\GoogleSitemap;

/**
 * A page class that decides its title, robots or fallback description in code (a getter override, a
 * MetaDescriptionFallback() method) is respected wherever the module reads them: the head, the X-Robots-Tag
 * header, the sitemap and the editor's search result preview. The checks are in GetterChecksTest.
 */
class SeoGettersTest extends FunctionalTest
{
    protected $usesDatabase = true;

    protected static $use_draft_site = false;

    protected static $extra_dataobjects = [
        SeoCheckTestPage::class,
        SeoCheckTestBlock::class,
        SeoGetterTestPage::class,
    ];

    protected function setUp(): void
    {
        parent::setUp();
        SSViewer::set_themes(['restruct/silverstripe-seo:/tests/themes/seotest', '$default']);
        $config = SiteConfig::current_site_config();
        $config->Title = 'Example Site';
        $config->write();
    }

    private function coursePage(array $data = []): SeoGetterTestPage
    {
        $page = SeoGetterTestPage::create(array_merge([
            'Title' => 'Course page',
            'URLSegment' => 'course',
            'CourseCode' => 'ABC',
            'Content' => '<p>The content of the course page, long enough to make a description from.</p>',
        ], $data));
        $page->write();
        $page->publishRecursive();
        return $page;
    }

    private function head(HTTPResponse $response): string
    {
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        preg_match('#<head>(.*)</head>#s', (string) $response->getBody(), $m);
        return $m[1] ?? '';
    }

    public function testAGetterTitleIsTheHeadTitle(): void
    {
        # The typed value in the column is ignored by the page class, so it is by the head too
        $this->coursePage(['MetaTitle' => 'Stale typed title']);
        $head = $this->head($this->get('course'));

        $this->assertStringContainsString('<title>Course ABC | Example Site</title>', $head);
        $this->assertStringContainsString('<meta property="og:title" content="Course ABC">', $head);
        $this->assertStringNotContainsString('Stale typed title', $head);
    }

    public function testAGetterNoindexReachesMetaAndHeader(): void
    {
        $this->coursePage(['Archived' => true, 'MetaRobotsNoIndex' => false]);
        $response = $this->get('course');

        $this->assertStringContainsString('<meta name="robots" content="noindex">', $this->head($response));
        $this->assertSame('noindex', $response->getHeader('X-Robots-Tag'));
    }

    public function testAGetterCanKeepAPageIndexableWhateverTheColumnSays(): void
    {
        $this->coursePage(['Archived' => false, 'MetaRobotsNoIndex' => true]);
        $response = $this->get('course');

        $this->assertStringNotContainsString('name="robots"', $this->head($response));
        $this->assertNull($response->getHeader('X-Robots-Tag'));
    }

    public function testMetaDescriptionFallbackComesBeforeTheModulesOwn(): void
    {
        $page = $this->coursePage(['FallbackText' => 'Course ABC: dates, prices and locations.']);
        $this->assertStringContainsString(
            '<meta name="description" content="Course ABC: dates, prices and locations.">',
            $this->head($this->get('course'))
        );
        $this->assertSame('Course ABC: dates, prices and locations.', $page->SeoDescriptionFallback());

        # A typed description still wins
        $page->MetaDescription = 'Typed by an editor.';
        $page->write();
        $page->publishRecursive();
        $this->assertStringContainsString('<meta name="description" content="Typed by an editor.">', $this->head($this->get('course')));
    }

    public function testAnEmptyMetaDescriptionFallbackLeavesItToTheModule(): void
    {
        $page = $this->coursePage(['FallbackText' => '']);
        $this->assertSame(
            'The content of the course page, long enough to make a description from.',
            $page->SeoDescriptionFallback()
        );
    }

    public function testTheFallbackSwitchAlsoSwitchesTheMethodOff(): void
    {
        SiteTree::config()->set('seo_description_fallback', false);
        $page = $this->coursePage(['FallbackText' => 'Course ABC: dates, prices and locations.']);
        $this->assertSame('', $page->SeoDescriptionFallback());
    }

    public function testTheSitemapLeavesOutAGetterNoindexPage(): void
    {
        if (!class_exists(GoogleSitemap::class)) {
            $this->markTestSkipped('wilr/silverstripe-googlesitemaps is not installed');
        }
        $archived = $this->coursePage(['URLSegment' => 'archived', 'Archived' => true]);
        $listed = $this->coursePage(['URLSegment' => 'listed', 'MetaRobotsNoIndex' => true]);

        $this->assertFalse((bool) $archived->canIncludeInGoogleSitemap());
        $this->assertTrue((bool) $listed->canIncludeInGoogleSitemap(), 'the getter says indexable, the column is ignored');
    }

    public function testTheSearchResultPreviewShowsTheGeneratedTitle(): void
    {
        $page = $this->coursePage(['MetaTitle' => 'Stale typed title']);
        $config = SeoSnippetPreviewField::create('SeoSnippetPreview', null, $page)->getClientConfig();

        # The script builds the title from the form's MetaTitle input, which the getter ignores: it gets the
        # page's own title instead
        $this->assertSame('Course ABC', $config['title']['pageTitle'] ?? null);
        $this->assertStringStartsWith('Course ABC', SeoSnippetPreviewField::create('SeoSnippetPreview', null, $page)->getPreviewTitle());
    }
}
