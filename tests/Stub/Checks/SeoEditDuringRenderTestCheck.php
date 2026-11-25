<?php

namespace Restruct\Silverstripe\SEO\Tests\Stub\Checks;

use Restruct\Silverstripe\SEO\Checks\AbstractCheck;
use Restruct\Silverstripe\SEO\Checks\CheckResult;
use Restruct\Silverstripe\SEO\Checks\PageAnalysis;
use SilverStripe\Dev\TestOnly;
use SilverStripe\ORM\DB;
use SilverStripe\ORM\FieldType\DBDatetime;

/**
 * Plays an editor saving the page while the check runs: moves the draft's LastEdited on, or (in block mode)
 * only lets the clock run on, as a block saved meanwhile would leave the page row alone.
 */
class SeoEditDuringRenderTestCheck extends AbstractCheck implements TestOnly
{
    private static string $id = 'edit-during-render';

    private static string $group = self::GROUP_CONTENT;

    private static string $severity = self::TIP;

    private static string $priority = self::LOW;

    public function title(): string
    {
        return 'Edits the page';
    }

    public function howToFix(): string
    {
        return '';
    }

    public static bool $blockMode = false;

    public function run(PageAnalysis $analysis): CheckResult
    {
        if (static::$blockMode) {
            # A block (or anything else the page shows) saved at 10:00:30, while the check runs
            DBDatetime::set_mock_now('2026-11-25 10:01:00');
            return $this->pass();
        }
        DB::prepared_query(
            'UPDATE "SiteTree" SET "LastEdited" = ? WHERE "ID" = ?',
            ['2026-11-25 10:00:30', $analysis->getPage()->ID]
        );
        return $this->pass();
    }
}
