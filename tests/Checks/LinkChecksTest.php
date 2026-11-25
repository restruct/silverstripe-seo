<?php

namespace Restruct\Silverstripe\SEO\Tests\Checks;

use Restruct\Silverstripe\SEO\Checks\LinkGraph;
use Restruct\Silverstripe\SEO\Checks\Rules\LinkTextCheck;
use SilverStripe\Assets\Dev\TestAssetStore;
use SilverStripe\Assets\File;
use SilverStripe\CMS\Model\RedirectorPage;
use SilverStripe\Control\Director;
use SilverStripe\ORM\DB;

/**
 * Checks 13-16: links in the rendered content, and the inbound links of the page.
 */
class LinkChecksTest extends ChecksTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        TestAssetStore::activate('SeoLinkChecksTest');
    }

    protected function tearDown(): void
    {
        TestAssetStore::reset();
        parent::tearDown();
    }

    private function withLinks(string $links): array
    {
        return ['Content' => static::GOOD_TEXT . '<p>' . $links . '</p>'];
    }

    public function testBrokenLinksPassesForLinksToPublishedPagesAndFiles(): void
    {
        $target = $this->page(['URLSegment' => 'target'], [], true);
        $file = File::create();
        $file->setFromString('A timetable', 'Uploads/timetable.txt');
        $file->write();
        $file->publishSingle();

        $page = $this->page($this->withLinks(
            '<a href="[sitetree_link,id=' . $target->ID . ']">the target page</a> '
            . '<a href="' . $file->getURL() . '">the timetable</a> '
            . '<a href="/target/some-action/5">an action on the target page</a> '
            # Link tracking checks that an anchor exists in the same text, so the target is there
            . '<a href="#prices">the prices below</a><h2 id="prices">Prices</h2> '
            . '<a href="mailto:info@example.com">email us</a> '
            . '<a href="https://external.example.org/x">an external site</a>'
        ));
        $this->assertCheckPasses($page, 'broken-links');
    }

    public function testBrokenLinksFailsForMissingPagesAndFiles(): void
    {
        $page = $this->page($this->withLinks(
            '<a href="/no-such-page">a page that is gone</a> '
            . '<a href="[sitetree_link,id=987654]">a deleted page</a> '
            . '<img src="/assets/Uploads/missing.jpg" alt="A missing picture of a bowl">'
        ));
        $entry = $this->assertCheckFails($page, 'broken-links', 'no-such-page');
        $details = implode("\n", $entry['details']);
        $this->assertStringContainsString('a deleted page', $details);
        $this->assertStringContainsString('missing.jpg', $details);
        $this->assertSame('problem', $entry['severity']);
    }

    public function testBrokenLinksReportsDraftOnlyTargets(): void
    {
        $this->page(['URLSegment' => 'not-yet-live']);
        $page = $this->page($this->withLinks('<a href="/not-yet-live">a draft page</a>'));
        $this->assertCheckFails($page, 'broken-links', 'not-yet-live');
    }

    public function testBrokenLinksInABlockAreFound(): void
    {
        $page = $this->page([], [['Title' => 'More', 'Content' => '<p><a href="/gone-too">gone</a></p>']]);
        $this->assertCheckFails($page, 'broken-links', 'gone-too');
    }

    public function testLinkTextPassesForDescriptiveText(): void
    {
        $target = $this->page([], [], true);
        $page = $this->page($this->withLinks('<a href="' . $target->Link() . '">our course timetable</a>'));
        # The footer's "Read more" sits outside <main>: not reported
        $this->assertCheckPasses($page, 'link-text');
    }

    public function testLinkTextFailsForEmptyAndGenericText(): void
    {
        $target = $this->page([], [], true);
        $href = $target->Link();
        $page = $this->page($this->withLinks(
            '<a href="' . $href . '">click here</a> <a href="' . $href . '">Lees meer</a> '
            . '<a href="' . $href . '"><img src="/assets/x.png" alt=""></a>'
        ));
        $entry = $this->assertCheckFails($page, 'link-text', 'click here');
        $details = implode("\n", $entry['details']);
        $this->assertStringContainsString('Lees meer', $details);
        $this->assertStringContainsString($href, $details, 'the empty link is listed by its target');
    }

    public function testLinkTextCountsAnImageAltOrAriaLabelAsText(): void
    {
        $target = $this->page([], [], true);
        $href = $target->Link();
        $page = $this->page($this->withLinks(
            '<a href="' . $href . '"><img src="/assets/x.png" alt="Our timetable"></a>'
            . '<a href="' . $href . '" aria-label="Open the timetable"></a>'
        ));
        $this->assertCheckPasses($page, 'link-text');
    }

    public function testLinkTextListIsConfigurable(): void
    {
        LinkTextCheck::config()->merge('non_descriptive_texts', ['en' => ['our course timetable']]);
        $target = $this->page([], [], true);
        $page = $this->page($this->withLinks('<a href="' . $target->Link() . '">our course timetable</a>'));
        $this->assertCheckFails($page, 'link-text');
    }

    public function testRedirectsPassesForDirectLinks(): void
    {
        $target = $this->page([], [], true);
        $this->assertCheckPasses($this->page($this->withLinks('<a href="' . $target->Link() . '">x</a>')), 'link-redirects');
    }

    public function testRedirectsFailsForALinkToARedirectorPage(): void
    {
        $target = $this->page([], [], true);
        $redirector = RedirectorPage::create([
            'Title' => 'Old courses',
            'URLSegment' => 'old-courses',
            'RedirectionType' => 'Internal',
            'LinkToID' => $target->ID,
        ]);
        $redirector->write();
        $redirector->publishSingle();
        $page = $this->page($this->withLinks('<a href="/old-courses">the old page</a>'));
        $this->assertCheckFails($page, 'link-redirects', 'old-courses');
    }

    public function testRedirectsFailsForHttpLinksOnAnHttpsSite(): void
    {
        Director::config()->set('alternate_base_url', 'https://www.example.com/');
        $page = $this->page($this->withLinks('<a href="http://external.example.org/">an insecure site</a>'));
        $this->assertCheckFails($page, 'link-redirects', 'http://external.example.org/');
    }

    public function testOrphanPassesForAPageInTheMenu(): void
    {
        $this->assertCheckPasses($this->page(['ShowInMenus' => true]), 'orphan-page');
    }

    public function testOrphanFailsWithoutMenuOrInboundLinks(): void
    {
        $this->assertCheckFails($this->page(['ShowInMenus' => false]), 'orphan-page');
    }

    public function testOrphanFailsUnderAParentHiddenFromTheMenu(): void
    {
        $parent = $this->page(['ShowInMenus' => false]);
        $this->assertCheckFails($this->page(['ShowInMenus' => true, 'ParentID' => $parent->ID]), 'orphan-page');
    }

    public function testOrphanPassesWithALinkFromAnotherPageOrBlock(): void
    {
        $page = $this->page(['ShowInMenus' => false]);
        $this->page($this->withLinks('<a href="[sitetree_link,id=' . $page->ID . ']">x</a>'));
        $this->assertCheckPasses($page, 'orphan-page');

        $other = $this->page(['ShowInMenus' => false]);
        $this->page([], [['Title' => 'See also', 'Content' => '<p><a href="[sitetree_link,id=' . $other->ID . ']">x</a></p>']]);
        $this->assertCheckPasses($other, 'orphan-page');
    }

    public function testOrphanIgnoresALinkToItself(): void
    {
        $page = $this->page(['ShowInMenus' => false]);
        $page->Content = static::GOOD_TEXT . '<p><a href="[sitetree_link,id=' . $page->ID . ']">this page</a></p>';
        $page->write();
        $this->assertCheckFails($page, 'orphan-page');
    }

    public function testLinkGraphIsCachedAndClearedOnPublish(): void
    {
        $page = $this->page(['ShowInMenus' => false]);
        $linker = $this->page();
        $graph = LinkGraph::singleton();
        $graph->clear();
        $this->assertSame(0, $graph->inboundCount($page->ID));

        # A link added by a query that bypasses the ORM hooks is not seen until the cache is cleared
        DB::prepared_query(
            'INSERT INTO "SiteTreeLink" ("ParentID", "ParentClass", "LinkedID") VALUES (?, ?, ?)',
            [$linker->ID, get_class($linker), $page->ID]
        );
        $this->assertSame(0, $graph->inboundCount($page->ID), 'cached');
        $linker->publishSingle();
        $this->assertSame(1, $graph->inboundCount($page->ID), 'cleared by the publish');
    }

    public function testTheHomepageIsNeverAnOrphan(): void
    {
        $this->assertCheckPasses($this->page(['URLSegment' => 'home', 'ShowInMenus' => false]), 'orphan-page');
    }
}
