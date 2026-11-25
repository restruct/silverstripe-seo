<?php

namespace Restruct\Silverstripe\SEO\Tests\Checks;

use Restruct\Silverstripe\SEO\Tests\Stub\Checks\SeoCheckTestPage;
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
        $this->assertCount(22, $ids, 'checks 1-21 and 23');
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
