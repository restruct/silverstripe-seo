<?php

namespace Restruct\Silverstripe\SEO\Tests\Migration;

use Restruct\Silverstripe\SEO\Tasks\MigrateFromSeoModuleTask;
use SilverStripe\PolyExecution\PolyOutput;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * The task's options and what it prints about them.
 */
class TaskOptionsMigrationTest extends SeoMigrationTestCase
{
    public function testAnUnknownSourceIsPrintedAsTextInTheBrowser(): void
    {
        $task = MigrateFromSeoModuleTask::create();
        $input = new ArrayInput(
            ['--source' => '<href=javascript:alert(1)>click</>'],
            new InputDefinition($task->getOptions())
        );
        $buffer = new BufferedOutput();
        # The browser run of a task (dev/tasks) writes HTML
        $output = new PolyOutput(PolyOutput::FORMAT_HTML, wrappedOutput: $buffer);

        $this->assertSame(Command::INVALID, $task->run($input, $output));
        $html = $buffer->fetch();
        $this->assertStringNotContainsString('<a', $html);
        $this->assertStringNotContainsString('href="javascript', $html);
        $this->assertStringContainsString('&lt;href=javascript:alert(1)&gt;click&lt;/&gt;', $html);
    }
}
