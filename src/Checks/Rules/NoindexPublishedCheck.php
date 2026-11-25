<?php

namespace Restruct\Silverstripe\SEO\Checks\Rules;

use Restruct\Silverstripe\SEO\Checks\AbstractCheck;
use Restruct\Silverstripe\SEO\Checks\CheckResult;
use Restruct\Silverstripe\SEO\Checks\PageAnalysis;

/**
 * Check 1: the page is published but asks search engines not to list it. Often intentional (a thank-you page),
 * sometimes a setting left on from before launch; the only check a noindex page gets.
 */
class NoindexPublishedCheck extends AbstractCheck
{
    private static string $id = 'noindex-published';

    private static string $group = self::GROUP_INDEXING;

    private static string $severity = self::PROBLEM;

    private static string $priority = self::HIGH;

    private static bool $runs_on_noindex = true;

    public function title(): string
    {
        return _t(__CLASS__ . '.Title', 'Published page hidden from search engines');
    }

    public function howToFix(): string
    {
        return _t(
            __CLASS__ . '.HowToFix',
            'If the page should be found, untick "Hide from search results (noindex)" and publish. If it is meant to stay out of search results, ignore this check for the page.'
        );
    }

    public function run(PageAnalysis $analysis): CheckResult
    {
        $page = $analysis->getPage();
        if (!$analysis->isNoindex() || !$page->isPublished()) {
            return $this->pass();
        }
        return $this->fail($this->_t(
            __CLASS__ . '.Message',
            'This page is published, but asks search engines not to list it (noindex). Is that intentional?'
        ));
    }
}
