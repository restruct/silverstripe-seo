<?php

namespace Restruct\Silverstripe\SEO\Tasks;

use Restruct\Silverstripe\SEO\Report\SiteCheck;
use RuntimeException;
use SilverStripe\Dev\BuildTask;
use SilverStripe\PolyExecution\PolyOutput;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;

/**
 * Checks every eligible page for the site report in one run: `sake tasks:seo-check-site`, for sites without
 * queuedjobs (with it, queue {@see \Restruct\Silverstripe\SEO\Jobs\SeoSiteCheckJob} instead).
 *
 * `--only-stale` skips pages whose stored result is fresh; `--limit=N` stops after N pages were rendered
 * (skipped pages do not count), so a cron can work through a large site a slice at a time. Renders as a visitor
 * or as `SiteCheck.run_as`, never as the member running the task; see {@see SiteCheck} for that choice and for
 * the side effects of rendering every page.
 */
class SeoCheckSiteTask extends BuildTask
{
    protected static string $commandName = 'seo-check-site';

    protected string $title = 'Check the SEO of every page';

    protected static string $description = 'Run the SEO checks on every eligible page and store the results for '
        . 'the site report (--only-stale, --limit=N)';

    public function getOptions(): array
    {
        return [
            new InputOption('limit', null, InputOption::VALUE_REQUIRED, 'Stop after rendering this many pages'),
            new InputOption('only-stale', null, InputOption::VALUE_NONE, 'Skip pages whose stored result is up to date'),
        ];
    }

    protected function execute(InputInterface $input, PolyOutput $output): int
    {
        $limitOption = $input->getOption('limit');
        $limit = null;
        if ($limitOption !== null && $limitOption !== false) {
            if (!ctype_digit((string) $limitOption) || (int) $limitOption < 1) {
                $output->writeln('Give --limit as a whole number of 1 or more.');
                return Command::INVALID;
            }
            $limit = (int) $limitOption;
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
        $output->writeln(sprintf('%d eligible pages%s.', count($ids), $onlyStale ? ', skipping up-to-date results' : ''));

        $counts = SiteCheck::emptyCounts();
        $rendered = 0;
        $done = 0;
        foreach ($ids as $id) {
            if ($limit !== null && $rendered >= $limit) {
                break;
            }
            $outcome = $siteCheck->checkPage($id, $onlyStale, $member);
            $counts[$outcome]++;
            $done++;
            if (in_array($outcome, [SiteCheck::CHECKED, SiteCheck::FAILED], true)) {
                $rendered++;
            }
        }

        $left = count($ids) - $done;
        $output->writeln(SiteCheck::describe($counts) . ($left > 0 ? sprintf(', %d left', $left) : '') . '.');
        return $counts[SiteCheck::FAILED] > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
