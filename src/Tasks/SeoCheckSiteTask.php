<?php

namespace Restruct\Silverstripe\SEO\Tasks;

use Restruct\Silverstripe\SEO\Checks\SiteData;
use Restruct\Silverstripe\SEO\Model\StaleContext;
use Restruct\Silverstripe\SEO\Report\SiteCheck;
use RuntimeException;
use SilverStripe\Dev\BuildTask;
use SilverStripe\PolyExecution\PolyOutput;
use SilverStripe\Versioned\Versioned;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;

/**
 * Checks every eligible page for the site report in one run: `sake tasks:seo-check-site`, for sites without
 * queuedjobs (with it, queue {@see \Restruct\Silverstripe\SEO\Jobs\SeoSiteCheckJob} instead).
 *
 * `--only-stale` skips pages whose stored result is fresh; `--limit=N` stops after N pages were checked
 * (skipped and failed pages do not count) and says where to go on; `--from-id=N` starts at that page ID, so a
 * cron can work through a large site a slice at a time. Renders as a visitor
 * or as `SiteCheck.run_as`, never as the member running the task; see {@see SiteCheck} for that choice and for
 * the side effects of rendering every page.
 */
class SeoCheckSiteTask extends BuildTask
{
    protected static string $commandName = 'seo-check-site';

    protected string $title = 'Check the SEO of every page';

    protected static string $description = 'Run the SEO checks on every eligible page and store the results for '
        . 'the site report (--only-stale, --limit=N, --from-id=N)';

    /**
     * It renders every page in one run: far longer than a web request may take, and a page render must not be
     * started by visiting a URL.
     */
    private static bool $can_run_in_browser = false;

    /**
     * The title in the site's locale; core's getDescription() already translates the description (key
     * `description`).
     */
    public function getTitle(): string
    {
        return _t(__CLASS__ . '.Title', 'Check the SEO of every page');
    }

    public function getOptions(): array
    {
        return [
            new InputOption('limit', null, InputOption::VALUE_REQUIRED, _t(__CLASS__ . '.OptionLimit', 'Stop after rendering this many pages')),
            new InputOption('only-stale', null, InputOption::VALUE_NONE, _t(__CLASS__ . '.OptionOnlyStale', 'Skip pages whose stored result is up to date')),
            new InputOption('from-id', null, InputOption::VALUE_REQUIRED, _t(__CLASS__ . '.OptionFromId', 'Start at the page with this ID (pages are checked by ID)')),
        ];
    }

    protected function execute(InputInterface $input, PolyOutput $output): int
    {
        $limit = $this->positiveInt($input, 'limit');
        $fromID = $this->positiveInt($input, 'from-id');
        if ($limit === false || $fromID === false) {
            $output->writeln(_t(__CLASS__ . '.InvalidNumber', 'Give --limit and --from-id as whole numbers of 1 or more.'));
            return Command::INVALID;
        }
        $onlyStale = (bool) $input->getOption('only-stale');

        $siteCheck = SiteCheck::singleton();
        try {
            $member = $siteCheck->runAs();
        } catch (RuntimeException $e) {
            $output->writeln($e->getMessage());
            return Command::FAILURE;
        }
        $ids = $siteCheck->pageIDs();
        if ($fromID !== null) {
            # Resume a run that stopped at --limit: the IDs are in order
            $ids = array_values(array_filter($ids, fn (int $id) => $id >= $fromID));
        }
        $output->writeln($onlyStale
            ? _t(__CLASS__ . '.EligibleSkipping', '{count} eligible pages, skipping up-to-date results.', ['count' => count($ids)])
            : _t(__CLASS__ . '.Eligible', '{count} eligible pages.', ['count' => count($ids)]));

        $counts = SiteCheck::emptyCounts();
        # One staleness context for the run: the site epoch and the config fingerprint are read once
        $context = $onlyStale ? StaleContext::create(Versioned::DRAFT) : null;
        # And one SiteData for the run: other pages' generated titles are computed once, not once per page
        $site = SiteData::create();
        $rendered = 0;
        $done = 0;
        foreach ($ids as $id) {
            if ($limit !== null && $rendered >= $limit) {
                break;
            }
            $outcome = $siteCheck->checkPage($id, $onlyStale, $member, $context, $site);
            $counts[$outcome]++;
            $done++;
            # Only stored checks count: a page that fails is logged and does not use up the limit
            if ($outcome === SiteCheck::CHECKED) {
                $rendered++;
            }
        }

        $left = count($ids) - $done;
        $output->writeln($left > 0
            ? _t(__CLASS__ . '.SummaryLeft', '{summary}, {left} left.', ['summary' => SiteCheck::describe($counts), 'left' => $left])
            : SiteCheck::describe($counts) . '.');
        if ($left > 0) {
            $output->writeln(_t(__CLASS__ . '.Continue', 'Continue with --from-id={id}.', ['id' => $ids[$done]]));
        }
        return $counts[SiteCheck::FAILED] > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * The option as a whole number of 1 or more; null when not given, false when not valid.
     */
    protected function positiveInt(InputInterface $input, string $name): int|false|null
    {
        $value = $input->getOption($name);
        if ($value === null || $value === false) {
            return null;
        }
        return ctype_digit((string) $value) && (int) $value >= 1 ? (int) $value : false;
    }
}
