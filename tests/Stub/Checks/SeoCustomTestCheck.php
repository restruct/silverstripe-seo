<?php

namespace Restruct\Silverstripe\SEO\Tests\Stub\Checks;

use Restruct\Silverstripe\SEO\Checks\AbstractCheck;
use Restruct\Silverstripe\SEO\Checks\CheckResult;
use Restruct\Silverstripe\SEO\Checks\PageAnalysis;
use SilverStripe\Dev\TestOnly;

/**
 * A project's own check, registered in YAML next to the module's: fails on pages whose title contains "Draft".
 */
class SeoCustomTestCheck extends AbstractCheck implements TestOnly
{
    private static string $id = 'custom-draft-title';

    private static string $group = self::GROUP_TITLE;

    private static string $severity = self::WARNING;

    private static string $priority = self::LOW;

    public function title(): string
    {
        return 'Title says "Draft"';
    }

    public function howToFix(): string
    {
        return 'Take "Draft" out of the title.';
    }

    public function run(PageAnalysis $analysis): CheckResult
    {
        return str_contains((string) $analysis->getPage()->Title, 'Draft')
            ? $this->fail('The title still says "Draft".')
            : $this->pass();
    }
}
