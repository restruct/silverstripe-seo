<?php

namespace Restruct\Silverstripe\SEO\Tests\Report;

use Restruct\Silverstripe\SEO\Checks\CheckRunner;
use Restruct\Silverstripe\SEO\Model\SeoCheckResult;
use Restruct\Silverstripe\SEO\Tasks\SeoCheckSiteTask;
use Restruct\Silverstripe\SEO\Tests\Checks\ChecksTestCase;
use SilverStripe\ORM\FieldType\DBDatetime;
use SilverStripe\PolyExecution\PolyOutput;
use SilverStripe\Security\Security;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * `sake tasks:seo-check-site`, for sites without queuedjobs: --limit and --only-stale.
 */
class SiteCheckTaskTest extends ChecksTestCase
{
    protected function tearDown(): void
    {
        DBDatetime::clear_mock_now();
        parent::tearDown();
    }

    protected function runTask(array $options, int $expected = Command::SUCCESS): string
    {
        $task = SeoCheckSiteTask::create();
        $input = new ArrayInput($options, new InputDefinition($task->getOptions()));
        $buffer = new BufferedOutput();
        $output = new PolyOutput(PolyOutput::FORMAT_ANSI, wrappedOutput: $buffer);
        $this->assertSame($expected, $task->run($input, $output));
        return $buffer->fetch();
    }

    public function testTheCommandName(): void
    {
        $this->assertSame('tasks:seo-check-site', SeoCheckSiteTask::create()->getName());
    }

    public function testEveryEligiblePageIsChecked(): void
    {
        $a = $this->page();
        $b = $this->page();
        $output = $this->runTask([]);
        $this->assertNotNull(SeoCheckResult::forPage($a));
        $this->assertNotNull(SeoCheckResult::forPage($b));
        $this->assertStringContainsString('2 checked', $output);
    }

    public function testLimitCapsTheNumberOfPagesRendered(): void
    {
        $this->page();
        $this->page();
        $this->page();
        $output = $this->runTask(['--limit' => '2']);
        $this->assertSame(2, SeoCheckResult::get()->count());
        $this->assertStringContainsString('2 checked', $output);
        $this->assertStringContainsString('1 left', $output);
    }

    public function testOnlyStaleSkipsFreshResults(): void
    {
        DBDatetime::set_mock_now('2026-11-25 10:00:00');
        $fresh = $this->page();
        $new = $this->page();
        DBDatetime::set_mock_now('2026-11-25 11:00:00');
        CheckRunner::singleton()->checkPage($fresh, Security::getCurrentUser());
        DBDatetime::set_mock_now('2026-11-25 12:00:00');

        # The fresh page does not count towards the limit: the one page rendered is the unchecked one
        $output = $this->runTask(['--only-stale' => true, '--limit' => '1']);
        $this->assertSame('2026-11-25 11:00:00', SeoCheckResult::forPage($fresh)->CheckedAt);
        $this->assertSame('2026-11-25 12:00:00', SeoCheckResult::forPage($new)?->CheckedAt);
        $this->assertStringContainsString('1 up to date', $output);

        # Without --only-stale a fresh result is checked again
        $this->runTask([]);
        $this->assertSame('2026-11-25 12:00:00', SeoCheckResult::forPage($fresh)->CheckedAt);
    }

    public function testAnInvalidLimitIsRefused(): void
    {
        $this->page();
        $output = $this->runTask(['--limit' => 'abc'], Command::INVALID);
        $this->assertStringContainsString('--limit', $output);
        $this->assertSame(0, SeoCheckResult::get()->count());
    }
}
