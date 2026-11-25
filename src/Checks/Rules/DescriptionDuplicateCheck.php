<?php

namespace Restruct\Silverstripe\SEO\Checks\Rules;

use Restruct\Silverstripe\SEO\Checks\AbstractCheck;
use Restruct\Silverstripe\SEO\Checks\CheckResult;
use Restruct\Silverstripe\SEO\Checks\PageAnalysis;

/**
 * Check 7: another indexable page has the same typed meta description (a copied page, usually).
 */
class DescriptionDuplicateCheck extends AbstractCheck
{
    private static string $id = 'description-duplicate';

    private static string $group = self::GROUP_DESCRIPTION;

    private static string $severity = self::WARNING;

    private static string $priority = self::MEDIUM;

    private static int $max_listed = 5;

    public function title(): string
    {
        return _t(__CLASS__ . '.Title', 'Meta description is unique');
    }

    public function howToFix(): string
    {
        return _t(
            __CLASS__ . '.HowToFix',
            'Write a description for this page that says what is different about it, or leave the field empty to use the page text.'
        );
    }

    public function run(PageAnalysis $analysis): CheckResult
    {
        $description = $analysis->typedDescription();
        if ($description === '') {
            return $this->pass();
        }
        $others = $analysis->site()->pagesWithDescription($analysis->getPage(), $description);
        $count = $others->count();
        if (!$count) {
            return $this->pass();
        }
        $details = [];
        foreach ($others->limit((int) static::config()->get('max_listed')) as $other) {
            $details[] = sprintf('%s (%s)', $other->Title, $other->RelativeLink());
        }
        return $this->fail(
            _t(__CLASS__ . '.Message', '{count} other page(s) have the same meta description.', ['count' => $count]),
            $details
        );
    }
}
