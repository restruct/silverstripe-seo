<?php

namespace Restruct\Silverstripe\SEO\Tests\Checks;

use Restruct\Silverstripe\SEO\Tests\Stub\Checks\SeoCheckTestPage;
use Restruct\Silverstripe\SEO\Checks\CheckRunner;
use SilverStripe\CMS\Controllers\RootURLController;
use SilverStripe\Control\Director;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\Session;
use SilverStripe\Core\Injector\Injector;

/**
 * Check 1 and the gating it does, and check 17 (canonical).
 */
class IndexingChecksTest extends ChecksTestCase
{
    public function testNoindexPassesForAnIndexablePublishedPage(): void
    {
        $this->assertCheckPasses($this->page([], [], true), 'noindex-published');
    }

    public function testNoindexFailsForAPublishedNoindexPage(): void
    {
        $entry = $this->assertCheckFails($this->page(['MetaRobotsNoIndex' => true], [], true), 'noindex-published');
        $this->assertSame('problem', $entry['severity']);
        $this->assertSame('high', $entry['priority']);
    }

    public function testNoindexPassesForADraftOnlyNoindexPage(): void
    {
        $this->assertCheckPasses($this->page(['MetaRobotsNoIndex' => true]), 'noindex-published');
    }

    public function testANoindexPageGetsOnlyTheNoindexCheck(): void
    {
        # The page would fail several other checks (no H1, a placeholder, little text)
        $page = $this->page(['MetaRobotsNoIndex' => true, 'HideH1' => true, 'Content' => '<p>TODO</p>'], [], true);
        $this->assertSame(['noindex-published'], array_keys($this->results($page)));
    }

    public function testAnIndexablePageGetsTheWholeCatalogue(): void
    {
        $ids = array_keys($this->results($this->page()));
        $this->assertContains('h1-missing', $ids);
        $this->assertContains('broken-links', $ids);
        $this->assertContains('noindex-published', $ids);
        $this->assertNotContains('focus-keyword', $ids, 'check 22 was dropped');
        $this->assertCount(23, $ids, 'checks 1-21 and 23, with 13b (unpublished link targets)');
    }

    public function testTheSiteWideNonLiveNoindexDoesNotCountAsThePageAskingForIt(): void
    {
        # A test environment that keeps itself out of search results: every page is noindex there, but that is
        # the environment, not the editor's choice, so the page is still checked
        SeoCheckTestPage::config()->set('seo_noindex_non_live', true);
        $this->assertFalse(Director::isLive());
        $page = $this->page([], [], true);
        $this->assertCheckPasses($page, 'noindex-published');
        $this->assertArrayHasKey('h1-missing', $this->results($page));
    }

    public function testARedirectToThePageItselfGetsItsOwnMessage(): void
    {
        $entry = $this->entry($this->page(['RedirectTo' => 'self']), CheckRunner::RENDER_ENTRY);

        $this->assertSame('fail', $entry['status']);
        $this->assertStringContainsString('its own address', $entry['message']);
        $this->assertStringContainsString('301', $entry['message']);
        $this->assertStringContainsString('browser', $entry['howToFix'], 'advice for a redirect to itself');
    }

    public function testARedirectElsewhereKeepsTheGeneralMessage(): void
    {
        $entry = $this->entry($this->page(['RedirectTo' => '/somewhere-else/']), CheckRunner::RENDER_ENTRY);

        $this->assertSame('fail', $entry['status']);
        $this->assertStringContainsString('it redirected (status 302)', $entry['message']);
        $this->assertStringNotContainsString('its own address', $entry['message']);
    }

    public function testTheHomepageIsRenderedAndChecked(): void
    {
        # The renderer used to answer the homepage with a 301 to the base URL, so nothing on it was checked
        $home = $this->page(['URLSegment' => RootURLController::get_homepage_link()], [], true);

        $results = $this->results($home);

        $this->assertArrayNotHasKey(CheckRunner::RENDER_ENTRY, $results, json_encode($results[CheckRunner::RENDER_ENTRY] ?? null));
        $this->assertSame('pass', $results['h1-missing']['status']);
    }

    public function testCanonicalPassesForASelfReference(): void
    {
        $this->assertCheckPasses($this->page(), 'canonical');
    }

    public function testCanonicalFailsWhenItPointsElsewhere(): void
    {
        $page = $this->page(['CanonicalURL' => 'https://other.example.org/original']);
        $this->assertCheckFails($page, 'canonical', 'https://other.example.org/original');
    }

    public function testCanonicalFailsWhenThereAreTwo(): void
    {
        $page = $this->page();
        $page->ExtraMeta = '<link rel="canonical" href="https://other.example.org/">';
        $page->write();
        $this->assertCheckFails($page, 'canonical');
    }

    public function testCanonicalPassesForTheHomepageAndAPageInsideAnHttpsCmsRequest(): void
    {
        # The panel runs inside a CMS request over https; the render must not come out as http (it did: the
        # module's own canonical then read http://host while the check expected https://host)
        $home = $this->page(['Title' => 'Home', 'URLSegment' => RootURLController::get_homepage_link()]);
        $page = $this->page();
        $this->inHttpsRequest(function () use ($home, $page) {
            $this->assertCheckPasses($home, 'canonical');
            $this->assertCheckPasses($page, 'canonical');
        });
    }

    public function testCanonicalPassesForTheHomepageAndAPageOnAnHttpsBaseUrlOutsideAnyRequest(): void
    {
        # A queued job or CLI task: no request of its own, the scheme comes from SS_BASE_URL
        Director::config()->set('default_base_url', 'https://www.example.test/');
        $home = $this->page(['Title' => 'Home', 'URLSegment' => RootURLController::get_homepage_link()]);
        $page = $this->page();
        $hadRequest = Injector::inst()->has(HTTPRequest::class);
        $previous = $hadRequest ? Injector::inst()->get(HTTPRequest::class) : null;
        Injector::inst()->unregisterNamedObject(HTTPRequest::class);
        try {
            $this->assertCheckPasses($home, 'canonical');
            $this->assertCheckPasses($page, 'canonical');
        } finally {
            if ($hadRequest) {
                Injector::inst()->registerService($previous, HTTPRequest::class);
            }
        }
    }

    public function testCanonicalWithAnotherSchemeFailsForTheHomepageAndAPage(): void
    {
        # The scheme is compared strictly: an http canonical on an https site sends search engines to http
        foreach ([RootURLController::get_homepage_link(), 'courses-scheme'] as $segment) {
            $url = $segment === 'courses-scheme' ? 'http://www.example.test/courses-scheme' : 'http://www.example.test/';
            $page = $this->page(['Title' => 'Page ' . $segment, 'URLSegment' => $segment, 'CanonicalURL' => $url]);
            $this->inHttpsRequest(fn () => $this->assertCheckFails($page, 'canonical', $url));
        }
    }

    public function testCanonicalComparesTheRootWithOrWithoutItsSlashAndTheHostInAnyCase(): void
    {
        # "https://host" and "https://HOST/" are the same URL (RFC 3986 6.2.3: an empty path is "/")
        $home = $this->page([
            'Title' => 'Home',
            'URLSegment' => RootURLController::get_homepage_link(),
            'CanonicalURL' => 'https://WWW.Example.TEST/',
        ]);
        $page = $this->page(['Title' => 'Slashed', 'URLSegment' => 'slashed', 'CanonicalURL' => 'https://WWW.Example.TEST/slashed/']);
        $this->inHttpsRequest(function () use ($home, $page) {
            $this->assertCheckPasses($home, 'canonical');
            $this->assertCheckPasses($page, 'canonical');
        });
    }

    /**
     * Run $fn inside a request over https for www.example.test, as the CMS panel runs inside one.
     */
    private function inHttpsRequest(callable $fn): void
    {
        $request = new HTTPRequest('POST', 'admin/pages/check');
        $request->setSession(new Session([]));
        $request->setScheme('https');
        $request->addHeader('Host', 'www.example.test');
        $hadRequest = Injector::inst()->has(HTTPRequest::class);
        $previous = $hadRequest ? Injector::inst()->get(HTTPRequest::class) : null;
        Injector::inst()->registerService($request, HTTPRequest::class);
        try {
            $fn();
        } finally {
            if ($hadRequest) {
                Injector::inst()->registerService($previous, HTTPRequest::class);
            } else {
                Injector::inst()->unregisterNamedObject(HTTPRequest::class);
            }
        }
    }
}
