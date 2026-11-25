<?php

namespace Restruct\Silverstripe\SEO\Tests;

use Restruct\Silverstripe\SEO\Tests\Stub\SeoTestPage;
use SilverStripe\Assets\Dev\TestAssetStore;
use SilverStripe\CMS\Model\RedirectorPage;
use SilverStripe\Control\Director;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Dev\FunctionalTest;
use SilverStripe\SiteConfig\SiteConfig;
use SilverStripe\View\SSViewer;

/**
 * What a visitor (or a crawler) actually receives: the rendered head and the response headers of a real request
 * through the page's controller and template.
 *
 * This is the test that matters most for SS6: the head hooks were renamed (MetaTags -> updateMetaTags,
 * MetaComponents -> updateMetaComponents) and an extension using the old names is simply never called. Only a
 * rendered page shows whether the tags are there.
 */
class SeoHeadOutputTest extends FunctionalTest
{
    use SeoTestImages;

    protected $usesDatabase = true;

    protected static $use_draft_site = false;

    protected static $extra_dataobjects = [
        SeoTestPage::class,
    ];

    protected function setUp(): void
    {
        parent::setUp();
        TestAssetStore::activate('SeoHeadOutputTest');
        # The module's own test theme, addressed through the module so it resolves wherever the module is installed
        SSViewer::set_themes(['restruct/silverstripe-seo:/tests/themes/seotest', '$default']);

        $config = SiteConfig::current_site_config();
        $config->Title = 'Example Site';
        $config->write();
    }

    protected function tearDown(): void
    {
        TestAssetStore::reset();
        parent::tearDown();
    }

    private function publishedPage(array $data = []): SeoTestPage
    {
        $page = SeoTestPage::create(array_merge([
            'Title' => 'About us',
            'URLSegment' => 'about-us',
            'Content' => '<p>We make things that last.</p>',
        ], $data));
        $page->write();
        $page->publishRecursive();
        return $page;
    }

    /**
     * The head of the response, so assertions cannot accidentally match body text.
     */
    private function head(HTTPResponse $response): string
    {
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $body = (string) $response->getBody();
        $this->assertMatchesRegularExpression('#<head>.*</head>#s', $body);
        preg_match('#<head>(.*)</head>#s', $body, $m);
        return $m[1];
    }

    public function testHeadContainsTitleDescriptionAndCanonical(): void
    {
        $this->publishedPage(['MetaTitle' => 'Who we are']);
        $head = $this->head($this->get('about-us'));

        $this->assertStringContainsString('<title>Who we are | Example Site</title>', $head);
        # The description fallback reaches the head; core alone outputs a description only when one was typed
        $this->assertStringContainsString('<meta name="description" content="We make things that last.">', $head);
        $this->assertStringContainsString(
            '<link rel="canonical" href="' . Director::absoluteURL('/about-us') . '">',
            $head
        );
    }

    public function testTitleIsEscaped(): void
    {
        $this->publishedPage(['MetaTitle' => 'Fish & <Chips>']);
        $head = $this->head($this->get('about-us'));
        $this->assertStringContainsString('<title>Fish &amp; &lt;Chips&gt; | Example Site</title>', $head);
    }

    public function testHeadContainsOpenGraphAndTwitterTags(): void
    {
        $image = $this->createTestImage('share.png', 1600, 900);
        $this->publishedPage([
            'MetaTitle' => 'Who we are',
            'SEOFBdescription' => 'Shared text.',
            'OGImageID' => $image->ID,
        ]);
        $head = $this->head($this->get('about-us'));

        $this->assertStringContainsString('<meta property="og:title" content="Who we are">', $head);
        $this->assertStringContainsString('<meta property="og:description" content="Shared text.">', $head);
        $this->assertStringContainsString('<meta property="og:type" content="website">', $head);
        $this->assertStringContainsString('<meta property="og:site_name" content="Example Site">', $head);
        $this->assertStringContainsString(
            '<meta property="og:url" content="' . Director::absoluteURL('/about-us') . '">',
            $head
        );
        $this->assertStringContainsString('<meta name="twitter:card" content="summary_large_image">', $head);

        # og:image is absolute and resized to the configured 1200x630 crop
        $this->assertMatchesRegularExpression('#<meta property="og:image" content="https?://[^"]+share[^"]*\.png">#', $head);
        $this->assertStringContainsString('<meta property="og:image:width" content="1200">', $head);
        $this->assertStringContainsString('<meta property="og:image:height" content="630">', $head);
    }

    public function testWithoutAnImageTheTwitterCardIsSummary(): void
    {
        $this->publishedPage();
        $head = $this->head($this->get('about-us'));
        $this->assertStringContainsString('<meta name="twitter:card" content="summary">', $head);
        $this->assertStringNotContainsString('og:image', $head);
    }

    public function testRobotsMetaAndHeader(): void
    {
        $this->publishedPage(['MetaRobotsNoIndex' => true, 'MetaRobotsNoCache' => true]);
        $response = $this->get('about-us');
        $head = $this->head($response);

        $this->assertStringContainsString('<meta name="robots" content="noindex, noarchive">', $head);
        $this->assertSame('noindex, noarchive', $response->getHeader('X-Robots-Tag'));
    }

    public function testIndexablePageHasNoRobotsTagOrHeader(): void
    {
        $this->publishedPage();
        $response = $this->get('about-us');
        $head = $this->head($response);

        $this->assertStringNotContainsString('name="robots"', $head);
        $this->assertNull($response->getHeader('X-Robots-Tag'));
    }

    public function testCanonicalLinkHeader(): void
    {
        $this->publishedPage();
        $response = $this->get('about-us');
        $this->assertSame(
            '<' . Director::absoluteURL('/about-us') . '>; rel="canonical"',
            $response->getHeader('Link')
        );
    }

    public function testCanonicalOverrideReachesTagAndHeader(): void
    {
        $this->publishedPage(['CanonicalURL' => 'https://other.example.org/original']);
        $response = $this->get('about-us');
        $head = $this->head($response);

        $this->assertStringContainsString('<link rel="canonical" href="https://other.example.org/original">', $head);
        $this->assertSame('<https://other.example.org/original>; rel="canonical"', $response->getHeader('Link'));
    }

    public function testActionUrlGetsItsOwnCanonicalAndThemeTitleVariable(): void
    {
        # The _detail template uses $MetaTags(false) plus <title>$SeoTitle</title>: the other way a theme can integrate
        $this->publishedPage(['MetaTitle' => 'Who we are']);
        $response = $this->get('about-us/detail');
        $head = $this->head($response);

        $this->assertStringContainsString('<title>Who we are | Example Site</title>', $head);
        $this->assertSame(1, substr_count($head, '<title>'), 'exactly one title tag');
        $canonical = Director::absoluteURL('/about-us/detail');
        $this->assertStringContainsString('<link rel="canonical" href="' . $canonical . '">', $head);
        $this->assertSame('<' . $canonical . '>; rel="canonical"', $response->getHeader('Link'));
    }

    public function testCanonicalKeepsEverySegmentOfAUrlHandler(): void
    {
        # archive/$Year!/$Month/$Day: four segments, one more than Action/ID/OtherID can hold
        $this->publishedPage();
        $response = $this->get('about-us/archive/2024/05/12?utm_source=mail');
        $head = $this->head($response);

        $canonical = Director::absoluteURL('/about-us/archive/2024/05/12');
        $this->assertStringContainsString('<link rel="canonical" href="' . $canonical . '">', $head);
        $this->assertSame('<' . $canonical . '>; rel="canonical"', $response->getHeader('Link'));
    }

    public function testCanonicalLowercasesTheActionSegment(): void
    {
        # Actions match case-insensitively, so /about-us/DETAIL renders the same page as /about-us/detail
        $this->publishedPage();
        $head = $this->head($this->get('about-us/DETAIL'));
        $this->assertStringContainsString(
            '<link rel="canonical" href="' . Director::absoluteURL('/about-us/detail') . '">',
            $head
        );
    }

    public function testHomepageActionCanonicalKeepsTheHomeSegment(): void
    {
        # The homepage's own link is the site root, but an action on it lives at /home/<action>; core's
        # SiteTree::RelativeLink keeps the segment only when it is given the action
        $this->publishedPage(['Title' => 'Home', 'URLSegment' => 'home']);
        $response = $this->get('home/detail');
        $head = $this->head($response);

        $canonical = Director::absoluteURL('/home/detail');
        $this->assertStringContainsString('<link rel="canonical" href="' . $canonical . '">', $head);
        $this->assertSame('<' . $canonical . '>; rel="canonical"', $response->getHeader('Link'));
    }

    public function testRedirectFromAnActionGetsNoRobotsOrCanonicalHeader(): void
    {
        # A redirect has no content to index or to canonicalise; the target answers for itself
        $this->autoFollowRedirection = false;
        $this->publishedPage(['MetaRobotsNoIndex' => true]);
        $response = $this->get('about-us/moved');

        $this->assertSame(302, $response->getStatusCode());
        $this->assertNull($response->getHeader('X-Robots-Tag'));
        $this->assertNull($response->getHeader('Link'));
    }

    public function testRedirectorPageGetsNoRobotsOrCanonicalHeader(): void
    {
        $this->autoFollowRedirection = false;
        $target = $this->publishedPage();
        # The flags are columns on every page; a redirector page shows no fields for them, but may hold a value
        # from before it became a redirector
        $redirector = RedirectorPage::create([
            'Title' => 'Old about',
            'URLSegment' => 'old-about',
            'RedirectionType' => 'Internal',
            'LinkToID' => $target->ID,
            'MetaRobotsNoIndex' => true,
        ]);
        $redirector->write();
        $redirector->publishRecursive();

        $response = $this->get('old-about');
        $this->assertSame(301, $response->getStatusCode());
        $this->assertNull($response->getHeader('X-Robots-Tag'));
        $this->assertNull($response->getHeader('Link'));
    }

    public function testActionReturningItsOwnResponseStillGetsTheRobotsHeader(): void
    {
        # A file download or JSON action replaces the controller's response object; noindex must survive that
        $this->publishedPage(['MetaRobotsNoIndex' => true]);
        $response = $this->get('about-us/download');

        $this->assertSame('file body', (string) $response->getBody());
        $this->assertSame('noindex', $response->getHeader('X-Robots-Tag'));
    }
}
