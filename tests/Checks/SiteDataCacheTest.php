<?php

namespace Restruct\Silverstripe\SEO\Tests\Checks;

use ReflectionProperty;
use Restruct\Silverstripe\SEO\Checks\CheckRunner;
use Restruct\Silverstripe\SEO\Checks\SiteData;
use Restruct\Silverstripe\SEO\Jobs\SeoSiteCheckJob;
use Restruct\Silverstripe\SEO\Report\SiteCheck;
use Restruct\Silverstripe\SEO\Tasks\SeoCheckSiteTask;
use Restruct\Silverstripe\SEO\Tests\Stub\Checks\SeoCheckTestBlock;
use Restruct\Silverstripe\SEO\Tests\Stub\Checks\SeoCheckTestPage;
use Restruct\Silverstripe\SEO\Tests\Stub\Checks\SeoSpySiteData;
use Restruct\Silverstripe\SEO\Tests\Stub\SeoGetterTestPage;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\ORM\DB;
use SilverStripe\PolyExecution\PolyOutput;
use SilverStripe\Security\Security;
use Symbiote\QueuedJobs\Services\AbstractQueuedJob;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * The titles of pages whose class generates them (getMetaTitle()) exist only in PHP, so the duplicate lookup
 * computes them: once per SiteData, and one SiteData serves a whole check run, a job step or a task run.
 */
class SiteDataCacheTest extends ChecksTestCase
{
    # Replaces the parent's list, so its classes are named again
    protected static $extra_dataobjects = [
        SeoCheckTestPage::class,
        SeoCheckTestBlock::class,
        SeoGetterTestPage::class,
    ];

    protected function setUp(): void
    {
        parent::setUp();
        SeoGetterTestPage::$titleWithParent = true;
        SeoSpySiteData::$created = 0;
        SeoSpySiteData::$titleLoads = 0;
    }

    protected function tearDown(): void
    {
        SeoGetterTestPage::$titleWithParent = false;
        parent::tearDown();
    }

    /**
     * $count course pages under one parent, with distinct generated titles.
     *
     * @return SeoGetterTestPage[]
     */
    private function coursePages(int $count): array
    {
        $parent = $this->page(['Title' => 'Courses']);
        $pages = [];
        for ($i = 1; $i <= $count; $i++) {
            $page = SeoGetterTestPage::create([
                'Title' => 'Course page ' . $i,
                'CourseCode' => 'C' . $i,
                'ParentID' => $parent->ID,
                'MetaDescription' => static::GOOD_DESCRIPTION . ' ' . $i,
                'Content' => static::GOOD_TEXT,
            ]);
            $page->write();
            $pages[] = $page;
        }
        return $pages;
    }

    /**
     * How many queries $fn runs (as StalenessTest counts them).
     */
    private function queries(callable $fn): int
    {
        $conn = DB::get_conn();
        $count = new ReflectionProperty(get_class($conn), 'queryCount');
        # Core counts queries only while showqueries is set; the whitelist mode counts without printing
        $_REQUEST['showqueries'] = 'whitelist';
        $before = $count->getValue($conn);
        try {
            $fn();
        } finally {
            unset($_REQUEST['showqueries']);
        }
        return $count->getValue($conn) - $before;
    }

    /**
     * Queries of a second title lookup on a SiteData that already did one.
     */
    private function queriesOfASecondLookup(array $pages): int
    {
        $site = SiteData::create();
        $site->pagesWithTitle($pages[0], 'Nothing like it')->count();
        return $this->queries(fn () => $site->pagesWithTitle($pages[1], 'Nothing like it either')->count());
    }

    public function testGeneratedTitlesAreComputedOncePerSiteData(): void
    {
        $few = $this->queriesOfASecondLookup($this->coursePages(2));
        $many = $this->queriesOfASecondLookup($this->coursePages(6));

        # Without the cache every lookup would read the parent of every course page again
        $this->assertSame($few, $many, 'a later lookup costs the same however many course pages there are');
    }

    public function testTheCachedTitlesStillFindDuplicates(): void
    {
        $pages = $this->coursePages(3);
        $twin = SeoGetterTestPage::create([
            'Title' => 'Twin', 'CourseCode' => 'C2', 'ParentID' => $pages[0]->ParentID, 'URLSegment' => 'twin',
        ]);
        $twin->write();
        $site = SiteData::create();

        $this->assertSame([], $site->pagesWithTitle($pages[0], 'Course C1 in Courses')->column('ID'));
        $this->assertSame([(int) $twin->ID], array_map('intval', $site->pagesWithTitle($pages[1], 'course c2 in courses')->column('ID')));
        $this->assertSame([(int) $pages[1]->ID], array_map('intval', $site->pagesWithTitle($twin, 'Course C2 in Courses')->column('ID')));
    }

    public function testOneSiteDataServesTheChecksOfSeveralPages(): void
    {
        $pages = $this->coursePages(3);
        $site = SeoSpySiteData::create();

        foreach ($pages as $page) {
            CheckRunner::singleton()->run($page, Security::getCurrentUser(), $site);
        }

        $this->assertSame(1, SeoSpySiteData::$titleLoads);
    }

    public function testTheTaskUsesOneSiteDataForTheRun(): void
    {
        $this->coursePages(3);
        Injector::inst()->load([SiteData::class => ['class' => SeoSpySiteData::class]]);

        $task = SeoCheckSiteTask::create();
        $input = new ArrayInput([], new InputDefinition($task->getOptions()));
        $task->run($input, new PolyOutput(PolyOutput::FORMAT_ANSI, wrappedOutput: new BufferedOutput()));

        $this->assertSame(1, SeoSpySiteData::$created, 'one SiteData for the run');
        $this->assertSame(1, SeoSpySiteData::$titleLoads);
    }

    public function testTheJobUsesOneSiteDataPerStep(): void
    {
        if (!class_exists(AbstractQueuedJob::class)) {
            $this->markTestSkipped('symbiote/silverstripe-queuedjobs is not installed');
        }
        $this->coursePages(3);
        SeoSiteCheckJob::config()->set('batch_size', 10);
        Injector::inst()->load([SiteData::class => ['class' => SeoSpySiteData::class]]);

        $job = new SeoSiteCheckJob(false);
        $job->setup();
        $job->process();

        $this->assertSame(1, SeoSpySiteData::$created, 'one SiteData for the step');
        $this->assertSame(1, SeoSpySiteData::$titleLoads);
    }

    public function testSiteCheckPassesItsSiteDataOn(): void
    {
        $pages = $this->coursePages(2);
        $site = SeoSpySiteData::create();
        SeoSpySiteData::$titleLoads = 0;

        foreach ($pages as $page) {
            SiteCheck::singleton()->checkPage((int) $page->ID, false, null, null, $site);
        }

        $this->assertSame(1, SeoSpySiteData::$titleLoads);
    }
}
