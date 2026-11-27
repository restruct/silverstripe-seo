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

    # Reads other pages (their titles, descriptions, links or link targets): stale when any page changes
    private static bool $cross_page = true;


    public function title(): string
    {
        return _t(__CLASS__ . '.Title', 'Meta description is unique');
    }

    /**
     * What the check looks at and why it matters, for the editor reading the "About this check" dialog.
     */
    public function about(): string
    {
        return _t(
            __CLASS__ . '.About',
            'Checks that no other page has the same meta description: the short text search engines may show under the title of a search result. When several pages of the site show up for one search, the same text under each makes them hard to tell apart.'
        );
    }

    public function failTitle(): string
    {
        return _t(__CLASS__ . '.FailTitle', 'Meta description is not unique');
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
        foreach ($others->limit(static::maxListed()) as $other) {
            $details[] = sprintf('%s (%s)', $other->Title, $other->RelativeLink());
        }
        # The list is limited in the query; count the rest from the total
        $details = $this->listed($details, $count);
        return $this->fail(
            $this->_t(__CLASS__ . '.Message', '{count} other page(s) have the same meta description.', ['count' => $count]),
            $details
        );
    }
}
