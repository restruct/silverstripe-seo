<?php

namespace Restruct\Silverstripe\SEO\Tests\Checks;

use Restruct\Silverstripe\SEO\Tests\Stub\Checks\SeoCheckTestPage;
use Restruct\Silverstripe\SEO\Checks\CheckRunner;
use SilverStripe\CMS\Controllers\RootURLController;
use SilverStripe\Control\Director;

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
}
