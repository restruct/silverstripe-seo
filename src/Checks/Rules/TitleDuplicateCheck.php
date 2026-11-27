<?php

namespace Restruct\Silverstripe\SEO\Checks\Rules;

use Restruct\Silverstripe\SEO\Checks\AbstractCheck;
use Restruct\Silverstripe\SEO\Checks\CheckResult;
use Restruct\Silverstripe\SEO\Checks\PageAnalysis;

/**
 * Check 4: another indexable page has the same title. Search engines then cannot tell the two apart in results.
 */
class TitleDuplicateCheck extends AbstractCheck
{
    private static string $id = 'title-duplicate';

    private static string $group = self::GROUP_TITLE;

    private static string $severity = self::WARNING;

    private static string $priority = self::HIGH;

    # Reads other pages (their titles, descriptions, links or link targets): stale when any page changes
    private static bool $cross_page = true;


    public function title(): string
    {
        return _t(__CLASS__ . '.Title', 'Title is unique');
    }

    public function failTitle(): string
    {
        return _t(__CLASS__ . '.FailTitle', 'Title is not unique');
    }

    public function howToFix(): string
    {
        return _t(
            __CLASS__ . '.HowToFix',
            'Give each page its own title, saying what is different about it. If the pages really are the same, keep one and point the other at it (canonical URL).'
        );
    }

    public function run(PageAnalysis $analysis): CheckResult
    {
        $title = trim($analysis->pageTitle());
        if ($title === '') {
            return $this->pass();
        }
        $others = $analysis->site()->pagesWithTitle($analysis->getPage(), $title);
        $count = $others->count();
        if (!$count) {
            return $this->pass();
        }
        $details = [];
        foreach ($others->limit(static::maxListed()) as $other) {
            $details[] = sprintf('%s (%s)', $other->Title, $other->RelativeLink());
        }
        # The list is limited in the query; count the rest from the total
        $details = $this->listed($details, $count);
        return $this->fail(
            $this->_t(__CLASS__ . '.Message', '{count} other page(s) have the same title: "{title}".', [
                'count' => $count,
                'title' => $title,
            ]),
            $details
        );
    }
}
