<?php

namespace Restruct\Silverstripe\SEO\Tests\Checks;

use Restruct\Silverstripe\SEO\Checks\CheckRunner;
use Restruct\Silverstripe\SEO\Model\SeoCheckIgnore;
use Restruct\Silverstripe\SEO\Model\SeoCheckResult;
use Restruct\Silverstripe\SEO\Tests\Stub\Checks\SeoEditDuringRenderTestCheck;
use Restruct\Silverstripe\SEO\Tests\Stub\Checks\SeoRaceTestExtension;
use SilverStripe\ORM\FieldType\DBDatetime;
use SilverStripe\Security\Security;
use SilverStripe\Versioned\Versioned;

/**
 * Stored results (one row per page and stage), staleness after a save, and per-page ignores.
 */
class CheckStorageTest extends ChecksTestCase
{
    protected static $required_extensions = [
        SeoCheckResult::class => [SeoRaceTestExtension::class],
    ];

    protected function tearDown(): void
    {
        SeoRaceTestExtension::$armed = false;
        SeoEditDuringRenderTestCheck::$blockMode = false;
        DBDatetime::clear_mock_now();
        parent::tearDown();
    }

    public function testCheckedAtIsWhenTheCheckStarted(): void
    {
        # So anything saved while it ran (a block, which does not touch the page row) is newer than CheckedAt
        DBDatetime::set_mock_now('2026-11-25 10:00:00');
        $page = $this->page();
        SeoEditDuringRenderTestCheck::$blockMode = true;
        CheckRunner::config()->merge('checks', ['edit-during-render' => ['class' => SeoEditDuringRenderTestCheck::class]]);
        $result = CheckRunner::singleton()->checkPage($page, Security::getCurrentUser());
        $this->assertSame('2026-11-25 10:00:00', $result->CheckedAt);
    }

    public function testStorageKeepsTheStagesApart(): void
    {
        $page = $this->page();
        $draft = SeoCheckResult::store($page, Versioned::DRAFT, []);
        $live = SeoCheckResult::store($page, Versioned::LIVE, []);
        $this->assertNotSame($draft->ID, $live->ID);
        $this->assertSame($draft->ID, SeoCheckResult::forPage($page, Versioned::DRAFT)->ID);
        $this->assertSame($live->ID, SeoCheckResult::forPage($page, Versioned::LIVE)->ID);
        $this->assertSame($draft->ID, SeoCheckResult::forPage($page)->ID, 'draft by default');

        SeoCheckResult::store($page, Versioned::LIVE, []);
        $this->assertSame(2, SeoCheckResult::get()->filter('PageID', $page->ID)->count(), 'updated, not added');

        SeoCheckResult::markStale($page);
        $this->assertTrue((bool) SeoCheckResult::forPage($page, Versioned::DRAFT)->Stale);
        $this->assertTrue((bool) SeoCheckResult::forPage($page, Versioned::LIVE)->Stale);
    }

    public function testARowStoredMeanwhileByAnotherRequestIsUpdated(): void
    {
        $page = $this->page();
        SeoRaceTestExtension::$armed = true;
        $result = SeoCheckResult::store($page, Versioned::DRAFT, []);
        $this->assertSame(1, SeoCheckResult::get()->filter('PageID', $page->ID)->count());
        $this->assertSame($result->ID, SeoCheckResult::forPage($page)->ID);
        $this->assertFalse((bool) SeoCheckResult::forPage($page)->Stale);
    }

    public function testAResultIsStoredStaleWhenThePageChangedDuringTheCheck(): void
    {
        DBDatetime::set_mock_now('2026-11-25 10:00:00');
        $page = $this->page();
        CheckRunner::config()->merge('checks', ['edit-during-render' => ['class' => SeoEditDuringRenderTestCheck::class]]);
        $result = CheckRunner::singleton()->checkPage($page, Security::getCurrentUser());
        $this->assertTrue((bool) $result->Stale);
    }

    public function testCheckingStoresOneRowPerPageAndStage(): void
    {
        DBDatetime::set_mock_now('2026-11-25 10:00:00');
        $page = $this->page(['HideH1' => true, 'ShowInMenus' => false]);
        $result = CheckRunner::singleton()->checkPage($page, Security::getCurrentUser());

        $this->assertInstanceOf(SeoCheckResult::class, $result);
        $this->assertSame($page->ID, (int) $result->PageID);
        $this->assertSame(Versioned::DRAFT, $result->Stage);
        $this->assertSame('2026-11-25 10:00:00', $result->CheckedAt);
        $this->assertFalse((bool) $result->Stale);
        # h1-missing and orphan-page are warnings; og-image is a tip; nothing is a problem
        $this->assertSame(0, (int) $result->Problems);
        $this->assertSame(2, (int) $result->Warnings);
        $this->assertGreaterThanOrEqual(1, (int) $result->Tips);
        $this->assertSame('fail', $result->getEntries()['h1-missing']['status']);

        DBDatetime::set_mock_now('2026-11-25 11:00:00');
        CheckRunner::singleton()->checkPage($page, Security::getCurrentUser());
        $this->assertSame(1, SeoCheckResult::get()->filter('PageID', $page->ID)->count(), 'updated, not added');
        $this->assertSame('2026-11-25 11:00:00', SeoCheckResult::forPage($page)->CheckedAt);
        DBDatetime::clear_mock_now();
    }

    public function testSavingThePageMarksTheStoredResultStale(): void
    {
        $page = $this->page();
        CheckRunner::singleton()->checkPage($page, Security::getCurrentUser());
        $this->assertFalse((bool) SeoCheckResult::forPage($page)->Stale);

        $page->Content = static::GOOD_TEXT . '<p>Changed.</p>';
        $page->write();
        $this->assertTrue((bool) SeoCheckResult::forPage($page)->Stale, 'refreshed on the next panel load');
    }

    public function testAnIgnoredCheckIsShownAsIgnoredAndNotCounted(): void
    {
        $page = $this->page(['HideH1' => true, 'ShowInMenus' => true]);
        $member = Security::getCurrentUser();
        $before = CheckRunner::singleton()->checkPage($page, $member);
        $warnings = (int) $before->Warnings;

        SeoCheckIgnore::ignore($page, 'h1-missing', $member);
        $after = CheckRunner::singleton()->checkPage($page, $member);

        $this->assertSame($warnings - 1, (int) $after->Warnings);
        $entry = $after->getEntries()['h1-missing'];
        $this->assertSame('fail', $entry['status'], 'still failing');
        $this->assertTrue($entry['ignored']);
        $this->assertSame($member->ID, $entry['ignoredBy']['memberID']);
        $this->assertNotEmpty($entry['ignoredBy']['date']);

        $ignore = SeoCheckIgnore::get()->filter(['PageID' => $page->ID, 'CheckID' => 'h1-missing'])->first();
        $this->assertSame($member->ID, (int) $ignore->MemberID);

        # Ignoring twice keeps one row; un-ignoring removes it and recounts the stored result
        SeoCheckIgnore::ignore($page, 'h1-missing', $member);
        $this->assertSame(1, SeoCheckIgnore::get()->filter('PageID', $page->ID)->count());
        SeoCheckIgnore::unignore($page, 'h1-missing');
        $this->assertSame(0, SeoCheckIgnore::get()->filter('PageID', $page->ID)->count());
        $this->assertSame($warnings, (int) SeoCheckResult::forPage($page)->Warnings);
    }

    public function testDeletingThePageRemovesItsResultsAndIgnores(): void
    {
        $page = $this->page(['HideH1' => true]);
        CheckRunner::singleton()->checkPage($page, Security::getCurrentUser());
        SeoCheckIgnore::ignore($page, 'h1-missing', Security::getCurrentUser());
        $id = $page->ID;
        $page->doArchive();
        $this->assertSame(0, SeoCheckResult::get()->filter('PageID', $id)->count());
        $this->assertSame(0, SeoCheckIgnore::get()->filter('PageID', $id)->count());
    }
}
