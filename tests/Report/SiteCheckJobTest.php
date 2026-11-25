<?php

namespace Restruct\Silverstripe\SEO\Tests\Report;

use Restruct\Silverstripe\SEO\Checks\CheckRunner;
use Restruct\Silverstripe\SEO\Jobs\SeoSiteCheckJob;
use Restruct\Silverstripe\SEO\Model\SeoCheckResult;
use Restruct\Silverstripe\SEO\Report\SiteCheck;
use Restruct\Silverstripe\SEO\Tests\Checks\ChecksTestCase;
use Restruct\Silverstripe\SEO\Tests\Stub\Report\SeoRenderSpyTestCheck;
use Restruct\Silverstripe\SEO\Tests\Stub\Report\SeoThrowingTestCheckRunner;
use ReflectionProperty;
use SilverStripe\Core\Injector\Injector;
use RuntimeException;
use SilverStripe\CMS\Model\RedirectorPage;
use SilverStripe\ORM\FieldType\DBDatetime;
use SilverStripe\Security\Member;
use SilverStripe\Security\Permission;
use SilverStripe\Security\Security;
use SilverStripe\Versioned\Versioned;
use Symbiote\QueuedJobs\Services\AbstractQueuedJob;

/**
 * The site-wide check as a queued job: batches, resuming from the stored job data, skipping fresh results, and
 * rendering as a visitor whoever queued the job.
 */
class SiteCheckJobTest extends ChecksTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (!class_exists(AbstractQueuedJob::class)) {
            $this->markTestSkipped('symbiote/silverstripe-queuedjobs is not installed');
        }
        SeoRenderSpyTestCheck::$seen = [];
        CheckRunner::config()->merge('checks', ['render-spy' => ['class' => SeoRenderSpyTestCheck::class]]);
    }

    protected function tearDown(): void
    {
        DBDatetime::clear_mock_now();
        parent::tearDown();
    }

    /**
     * One process() step, then the job rebuilt from its stored data, as the queue does between steps.
     */
    protected function step(SeoSiteCheckJob $job): SeoSiteCheckJob
    {
        $job->process();
        $data = $job->getJobData();
        $next = new SeoSiteCheckJob();
        # The queue stores jobData serialised; a round trip catches anything that does not survive it
        $next->setJobData(
            $data->totalSteps,
            $data->currentStep,
            $data->isComplete,
            unserialize(serialize($data->jobData)),
            $data->messages
        );
        return $next;
    }

    public function testTheJobChecksEveryEligiblePageInBatchesAndResumes(): void
    {
        SeoSiteCheckJob::config()->set('batch_size', 2);
        $pages = [$this->page(), $this->page(), $this->page()];
        RedirectorPage::create(['Title' => 'Elsewhere', 'ExternalURL' => 'https://example.com'])->write();

        $job = new SeoSiteCheckJob();
        $job->setup();
        $this->assertSame(3, (int) $job->getJobData()->totalSteps, 'redirectors are not eligible');

        $job = $this->step($job);
        $this->assertSame(2, (int) $job->getJobData()->currentStep);
        $this->assertFalse((bool) $job->getJobData()->isComplete);
        $this->assertSame(2, SeoCheckResult::get()->count());

        # A restart after a pause keeps the remaining pages
        $job->prepareForRestart();
        $job = $this->step($job);
        $this->assertTrue((bool) $job->getJobData()->isComplete);
        $this->assertSame(3, (int) $job->getJobData()->currentStep);
        foreach ($pages as $page) {
            $this->assertNotNull(SeoCheckResult::forPage($page), "page {$page->ID} checked");
        }
        $this->assertSame(3, SeoCheckResult::get()->count());
        $this->assertStringContainsString('3 checked', implode("\n", (array) $job->getJobData()->messages));
    }

    public function testFreshResultsAreSkipped(): void
    {
        DBDatetime::set_mock_now('2026-11-25 10:00:00');
        $fresh = $this->page();
        $stale = $this->page();
        DBDatetime::set_mock_now('2026-11-25 11:00:00');
        CheckRunner::singleton()->checkPage($fresh, Security::getCurrentUser());
        CheckRunner::singleton()->checkPage($stale, Security::getCurrentUser());
        SeoCheckResult::markStale($stale);
        DBDatetime::set_mock_now('2026-11-25 12:00:00');
        SeoRenderSpyTestCheck::$seen = [];

        $job = new SeoSiteCheckJob();
        $job->setup();
        $job = $this->step($job);

        $this->assertTrue((bool) $job->getJobData()->isComplete);
        $this->assertArrayNotHasKey($fresh->ID, SeoRenderSpyTestCheck::$seen, 'a fresh result is not rendered again');
        $this->assertArrayHasKey($stale->ID, SeoRenderSpyTestCheck::$seen);
        $this->assertSame('2026-11-25 11:00:00', SeoCheckResult::forPage($fresh)->CheckedAt);
        $this->assertSame('2026-11-25 12:00:00', SeoCheckResult::forPage($stale)->CheckedAt);
        $this->assertFalse((bool) SeoCheckResult::forPage($stale)->Stale);
        $this->assertStringContainsString('1 up to date', implode("\n", (array) $job->getJobData()->messages));
    }

    public function testAllPagesAreCheckedWhenNotOnlyStale(): void
    {
        DBDatetime::set_mock_now('2026-11-25 10:00:00');
        $page = $this->page();
        DBDatetime::set_mock_now('2026-11-25 11:00:00');
        CheckRunner::singleton()->checkPage($page, Security::getCurrentUser());
        DBDatetime::set_mock_now('2026-11-25 12:00:00');

        $job = new SeoSiteCheckJob(false);
        $job->setup();
        $this->step($job);
        $this->assertSame('2026-11-25 12:00:00', SeoCheckResult::forPage($page)->CheckedAt);
    }

    public function testTheJobRendersTheDraftAsAVisitorWhoeverQueuedIt(): void
    {
        # Logged in as ADMIN (ChecksTestCase): the queue runs a job as the member who queued it
        $admin = Security::getCurrentUser();
        $this->assertNotNull($admin);
        $draftOnly = $this->page();
        $this->assertFalse($draftOnly->isPublished());

        $job = new SeoSiteCheckJob();
        $job->setup();
        $this->step($job);

        $seen = SeoRenderSpyTestCheck::$seen[$draftOnly->ID] ?? null;
        $this->assertNotNull($seen, 'the draft-only page was checked');
        $this->assertSame(0, $seen['member'], 'checked as nobody, not as the admin');
        $this->assertSame(200, $seen['status'], 'the draft renders for the site run');
        $this->assertFalse($seen['cmsMeta'], 'no CMS-only markup in the render');
        $this->assertArrayNotHasKey(CheckRunner::RENDER_ENTRY, SeoCheckResult::forPage($draftOnly)->getEntries());

        # Everything is put back afterwards
        $this->assertTrue(Versioned::get_draft_site_secured(), 'drafts are secured again');
        $this->assertSame($admin->ID, Security::getCurrentUser()?->ID);
    }

    /**
     * Versioned's raw draft-security static: null means "from config", which get_draft_site_secured() hides.
     */
    protected function draftSecuredStatic(): ReflectionProperty
    {
        return new ReflectionProperty(Versioned::class, 'is_draft_site_secured');
    }

    public function testTheRawDraftSecuritySettingIsPutBack(): void
    {
        $page = $this->page();
        $static = $this->draftSecuredStatic();
        $before = $static->getValue();
        try {
            $static->setValue(null, null);
            $this->assertSame(SiteCheck::CHECKED, SiteCheck::singleton()->checkPage($page->ID, false, null));
            $this->assertNull($static->getValue(), 'still "from config", not pinned to the resolved value');

            $static->setValue(null, false);
            SiteCheck::singleton()->checkPage($page->ID, false, null);
            $this->assertFalse($static->getValue());
        } finally {
            $static->setValue(null, $before);
        }
    }

    public function testTheDraftSecuritySettingIsPutBackWhenTheCheckThrows(): void
    {
        $page = $this->page();
        $static = $this->draftSecuredStatic();
        $before = $static->getValue();
        Injector::inst()->registerService(new SeoThrowingTestCheckRunner(), CheckRunner::class);
        try {
            $static->setValue(null, null);
            $this->assertSame(SiteCheck::FAILED, SiteCheck::singleton()->checkPage($page->ID, false, null));
            $this->assertNull($static->getValue());
            $this->assertTrue(Versioned::get_draft_site_secured());
        } finally {
            Injector::inst()->unregisterNamedObject(CheckRunner::class);
            $static->setValue(null, $before);
        }
    }

    public function testAPageVisitorsMayNotSeeIsNotStored(): void
    {
        $members = $this->page(['CanViewType' => 'LoggedInUsers']);
        $open = $this->page();

        $job = new SeoSiteCheckJob();
        $job->setup();
        $job = $this->step($job);

        $this->assertNull(SeoCheckResult::forPage($members), 'not rendered as a visitor, so no result');
        $this->assertNotNull(SeoCheckResult::forPage($open));
        $this->assertStringContainsString('1 not visible', implode("\n", (array) $job->getJobData()->messages));
    }

    public function testAConfiguredMemberIsUsedForTheRender(): void
    {
        $editor = Member::create(['Email' => 'seo-check@example.com', 'FirstName' => 'Site check']);
        $editor->write();
        $editor->addToGroupByCode('seo-checkers', 'SEO checkers');
        $group = $editor->Groups()->first();
        Permission::grant($group->ID, 'CMS_ACCESS_CMSMain');
        SiteCheck::config()->set('run_as', 'seo-check@example.com');
        $page = $this->page();

        $this->assertSame($editor->ID, SiteCheck::singleton()->runAs()?->ID);
        $job = new SeoSiteCheckJob();
        $job->setup();
        $this->step($job);
        $this->assertSame($editor->ID, SeoRenderSpyTestCheck::$seen[$page->ID]['member']);
    }

    public function testAConfiguredMemberThatDoesNotExistStopsTheRun(): void
    {
        SiteCheck::config()->set('run_as', 'nobody@example.com');
        $this->page();
        $job = new SeoSiteCheckJob();
        $this->expectException(RuntimeException::class);
        $job->setup();
    }
}
