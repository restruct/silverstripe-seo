<?php

namespace Restruct\Silverstripe\SEO\Jobs;

use Restruct\Silverstripe\SEO\Report\SiteCheck;
use SilverStripe\Core\Config\Configurable;
use Symbiote\QueuedJobs\Services\AbstractQueuedJob;
use Symbiote\QueuedJobs\Services\QueuedJob;

/**
 * Checks every eligible page for the site report, a batch of pages per process() step, and stores the results
 * as the page editor's panel does. Needs symbiote/silverstripe-queuedjobs (a suggest of this module); without
 * it, `sake tasks:seo-check-site` does the same in one run.
 *
 * The pages still to do are kept in the job data (IDs only), so a job that is paused, or picked up again after
 * the runner died, carries on where it was. By default a page whose stored result is fresh is skipped
 * (`new SeoSiteCheckJob(false)` checks every page again).
 *
 * The render runs as a visitor (or the member in `SiteCheck.run_as`), never as the member the queue runs the
 * job as: see {@see SiteCheck} for why, and for the side effects of rendering every page.
 */
class SeoSiteCheckJob extends AbstractQueuedJob
{
    use Configurable;

    /**
     * Pages rendered per process() step: the state is saved and the health check sees progress after each.
     */
    private static int $batch_size = 10;

    /**
     * The queue: rendering every page is a large job, run by the runner of the large queue.
     */
    private static string $queue = QueuedJob::LARGE;

    /**
     * Page IDs whose failure is kept for the final message; the rest is in the log.
     */
    protected const FAILED_SHOWN = 20;

    public function __construct(bool $onlyStale = true)
    {
        # Also called without arguments when the queue rebuilds the job; the stored job data then replaces this
        $this->onlyStale = $onlyStale;
    }

    public function getTitle()
    {
        return _t(__CLASS__ . '.Title', 'SEO checks of the whole site');
    }

    public function getJobType()
    {
        return (string) static::config()->get('queue');
    }

    public function setup()
    {
        parent::setup();
        # Fails the job straight away when run_as names no member
        SiteCheck::singleton()->runAs();
        $ids = SiteCheck::singleton()->pageIDs();
        $this->remaining = $ids;
        $this->counts = SiteCheck::emptyCounts();
        $this->failedIDs = [];
        $this->totalSteps = count($ids);
        $this->currentStep = 0;
    }

    public function process()
    {
        $remaining = array_values((array) $this->remaining);
        if (!$remaining) {
            $this->finish();
            return;
        }
        $size = max(1, (int) static::config()->get('batch_size'));
        $batch = array_slice($remaining, 0, $size);
        $siteCheck = SiteCheck::singleton();
        $member = $siteCheck->runAs();
        $onlyStale = (bool) $this->onlyStale;

        # Read, change, write back: job data properties are copies (__get), not references
        $counts = array_merge(SiteCheck::emptyCounts(), (array) $this->counts);
        $failed = (array) $this->failedIDs;
        foreach ($batch as $id) {
            $outcome = $siteCheck->checkPage((int) $id, $onlyStale, $member);
            $counts[$outcome] = ($counts[$outcome] ?? 0) + 1;
            if ($outcome === SiteCheck::FAILED && count($failed) < static::FAILED_SHOWN) {
                $failed[] = (int) $id;
            }
        }
        $this->counts = $counts;
        $this->failedIDs = $failed;
        $this->remaining = array_slice($remaining, count($batch));
        $this->currentStep = (int) $this->currentStep + count($batch);

        if (!$this->remaining) {
            $this->finish();
        }
    }

    protected function finish(): void
    {
        $this->addMessage(SiteCheck::describe((array) $this->counts));
        if ($this->failedIDs) {
            $this->addMessage(sprintf('Failed page IDs: %s (see the log)', implode(', ', (array) $this->failedIDs)), 'WARNING');
        }
        $this->isComplete = true;
    }
}
