<?php

namespace Restruct\Silverstripe\SEO\Tests;

use Restruct\Silverstripe\SEO\Tests\Stub\SeoTestItem;
use Restruct\Silverstripe\SEO\Tests\Stub\SeoTestPage;
use SilverStripe\Assets\Dev\TestAssetStore;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Dev\FunctionalTest;
use SilverStripe\ErrorPage\ErrorPage;
use SilverStripe\SiteConfig\SiteConfig;
use SilverStripe\View\SSViewer;

/**
 * An error response has no canonical of its own: neither the link tag, og:url nor the Link header may point a
 * search engine at the URL that failed (or at the error page's own URL, which is not meant to be indexed either).
 *
 * The error page cases run only when silverstripe/errorpage is installed; the module does not require it.
 */
class SeoErrorResponseTest extends FunctionalTest
{
    protected $usesDatabase = true;

    protected static $use_draft_site = false;

    protected static $extra_dataobjects = [
        SeoTestPage::class,
        SeoTestItem::class,
    ];

    private const THEMES = ['restruct/silverstripe-seo:/tests/themes/seotest', '$default'];

    protected function setUp(): void
    {
        parent::setUp();
        TestAssetStore::activate('SeoErrorResponseTest');
        SSViewer::set_themes(self::THEMES);
        # ErrorPageController::init() resets the themes to this config value
        SSViewer::config()->set('themes', self::THEMES);

        $config = SiteConfig::current_site_config();
        $config->Title = 'Example Site';
        $config->write();

        $page = SeoTestPage::create(['Title' => 'About us', 'URLSegment' => 'about-us']);
        $page->write();
        $page->publishRecursive();
    }

    protected function tearDown(): void
    {
        TestAssetStore::reset();
        parent::tearDown();
    }

    private function errorPage(): ErrorPage
    {
        if (!class_exists(ErrorPage::class)) {
            $this->markTestSkipped('silverstripe/errorpage is not installed');
        }
        # Written by hand: whether a test database gets the default error pages is not this test's concern
        $page = ErrorPage::get()->filter('ErrorCode', 404)->first() ?: ErrorPage::create([
            'Title' => 'Page not found',
            'URLSegment' => 'page-not-found',
            'ErrorCode' => 404,
            'Content' => '<p>Sorry, it is not here.</p>',
        ]);
        $page->write();
        $page->publishRecursive();
        return $page;
    }

    private function assertNoCanonical(HTTPResponse $response, ?string $body = null): void
    {
        $body ??= (string) $response->getBody();
        $this->assertStringNotContainsString('rel="canonical"', $body);
        $this->assertStringNotContainsString('og:url', $body);
        $this->assertNull($response->getHeader('Link'), 'no Link header on an error response');
    }

    public function testErrorPageAtItsOwnUrlHasNoCanonical(): void
    {
        $this->errorPage();
        $response = $this->get('page-not-found');
        $this->assertSame(404, $response->getStatusCode());
        $this->assertStringContainsString('Sorry, it is not here.', (string) $response->getBody());
        $this->assertNoCanonical($response);
    }

    public function testMissingUrlRenderedByTheErrorPageHasNoCanonical(): void
    {
        $this->errorPage();
        $response = $this->get('does-not-exist');
        $this->assertSame(404, $response->getStatusCode());
        $this->assertStringContainsString('Sorry, it is not here.', (string) $response->getBody());
        $this->assertNoCanonical($response);
    }

    public function testStaticErrorFileHasNoCanonical(): void
    {
        # The error-404.html file the web server serves when PHP cannot run is rendered from the same page
        $page = $this->errorPage();
        $this->assertTrue($page->writeStaticPage(), 'static error page written');
        $html = (string) ErrorPage::get_content_for_errorcode(404);
        $this->assertStringContainsString('Sorry, it is not here.', $html);
        $this->assertStringNotContainsString('rel="canonical"', $html);
        $this->assertStringNotContainsString('og:url', $html);
    }

    public function testActionHttpErrorWithErrorPageHasNoCanonical(): void
    {
        # /about-us/item/999999: the action answers httpError(404), which the error page renders
        $this->errorPage();
        $response = $this->get('about-us/item/999999');
        $this->assertSame(404, $response->getStatusCode());
        $this->assertNoCanonical($response);
    }

    public function testActionHttpErrorWithoutErrorPageHasNoCanonical(): void
    {
        $response = $this->get('about-us/item/999999');
        $this->assertSame(404, $response->getStatusCode());
        $this->assertNoCanonical($response);
    }

    public function testActionSettingAnErrorStatusItselfHasNoCanonical(): void
    {
        # An action that sets 410 on the response and still renders the page template
        $response = $this->get('about-us/gone');
        $this->assertSame(410, $response->getStatusCode());
        $this->assertStringContainsString('<title>', (string) $response->getBody(), 'the template was rendered');
        $this->assertNoCanonical($response);
    }
}
