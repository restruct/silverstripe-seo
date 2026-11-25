<?php

namespace Restruct\Silverstripe\SEO\Tests\Checks;

use Restruct\Silverstripe\SEO\Checks\CheckRunner;
use Restruct\Silverstripe\SEO\Model\SeoCheckIgnore;
use Restruct\Silverstripe\SEO\Model\SeoCheckResult;
use SilverStripe\ORM\FieldType\DBDatetime;
use SilverStripe\Security\Security;
use SilverStripe\Versioned\Versioned;

/**
 * Stored results (one row per page and stage), staleness after a save, and per-page ignores.
 */
class CheckStorageTest extends ChecksTestCase
{
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
