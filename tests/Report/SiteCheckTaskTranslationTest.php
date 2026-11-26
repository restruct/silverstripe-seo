<?php

namespace Restruct\Silverstripe\SEO\Tests\Report;

use Restruct\Silverstripe\SEO\Report\SiteCheck;
use Restruct\Silverstripe\SEO\Tasks\SeoCheckSiteTask;
use Restruct\Silverstripe\SEO\Tests\Checks\ChecksTestCase;
use SilverStripe\i18n\i18n;
use SilverStripe\PolyExecution\PolyOutput;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * `sake tasks:seo-check-site` under a Dutch locale prints Dutch only: no line mixes in the English summary,
 * the resume hint or the task title. Core's own "Running task" frame is not this module's to translate.
 */
class SiteCheckTaskTranslationTest extends ChecksTestCase
{
    /**
     * English fragments of the task's own output; none may be left under nl_NL.
     */
    private const ENGLISH = [
        'Check the SEO of every page',
        'eligible pages',
        'skipping up-to-date',
        ' left',
        'Continue with',
        'Give --limit',
        'whole numbers',
        ' checked',
        'up to date',
        'not visible',
        'run_as names no member',
    ];

    protected function runDutch(array $options, int $expected): string
    {
        return i18n::with_locale('nl_NL', function () use ($options, $expected) {
            $task = SeoCheckSiteTask::create();
            $input = new ArrayInput($options, new InputDefinition($task->getOptions()));
            $buffer = new BufferedOutput();
            $output = new PolyOutput(PolyOutput::FORMAT_ANSI, wrappedOutput: $buffer);
            $this->assertSame($expected, $task->run($input, $output));
            return $buffer->fetch();
        });
    }

    protected function assertNoEnglish(string $output): void
    {
        foreach (self::ENGLISH as $fragment) {
            $this->assertStringNotContainsString($fragment, $output, "English left in:\n$output");
        }
    }

    public function testARunThatStopsAtTheLimitIsDutch(): void
    {
        $this->page();
        $second = $this->page();
        $output = $this->runDutch(['--limit' => '1', '--only-stale' => true], Command::SUCCESS);
        $this->assertNoEnglish($output);
        $this->assertStringContainsString('Ga verder met --from-id=' . $second->ID, $output);
    }

    public function testARefusedOptionIsDutch(): void
    {
        $output = $this->runDutch(['--limit' => 'abc'], Command::INVALID);
        $this->assertNoEnglish($output);
        $this->assertStringContainsString('--limit', $output);
    }

    public function testAMissingRunAsMemberIsDutch(): void
    {
        SiteCheck::config()->set('run_as', 'nobody@example.com');
        $output = $this->runDutch([], Command::FAILURE);
        $this->assertNoEnglish($output);
        $this->assertStringContainsString('nobody@example.com', $output);
    }

    public function testTheTitleDescriptionAndOptionsAreDutch(): void
    {
        $texts = i18n::with_locale('nl_NL', function () {
            $task = SeoCheckSiteTask::create();
            $texts = [$task->getTitle(), SeoCheckSiteTask::getDescription()];
            foreach ($task->getOptions() as $option) {
                $texts[] = $option->getDescription();
            }
            return implode("\n", $texts);
        });
        foreach (['Check the SEO', 'Run the SEO checks', 'Stop after', 'Skip pages', 'Start at the page'] as $fragment) {
            $this->assertStringNotContainsString($fragment, $texts);
        }
    }
}
