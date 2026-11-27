<?php

namespace Restruct\Silverstripe\SEO\Checks\Rules;

use Restruct\Silverstripe\SEO\Checks\AbstractCheck;
use Restruct\Silverstripe\SEO\Checks\CheckResult;
use Restruct\Silverstripe\SEO\Checks\PageAnalysis;

/**
 * Check 9: the rendered page has no H1 anywhere in its body. Screen reader users navigate by headings, and the
 * main heading tells search engines what the page is about.
 */
class H1MissingCheck extends AbstractCheck
{
    private static string $id = 'h1-missing';

    private static string $group = self::GROUP_CONTENT;

    private static string $severity = self::WARNING;

    private static string $priority = self::MEDIUM;

    private static bool $needs_render = true;

    public function title(): string
    {
        return _t(__CLASS__ . '.Title', 'Main heading (H1)');
    }

    public function passTitle(): string
    {
        return _t(__CLASS__ . '.PassTitle', 'Page has a main heading (H1)');
    }

    public function failTitle(): string
    {
        return _t(__CLASS__ . '.FailTitle', 'Main heading (H1) is missing');
    }

    public function howToFix(): string
    {
        return _t(
            __CLASS__ . '.HowToFix',
            'Give the page one main heading (Heading 1) that says what it is about. If the template should print the page title as H1, ask the developer.'
        );
    }

    public function run(PageAnalysis $analysis): CheckResult
    {
        # The whole body: a theme often prints the H1 in a page header above <main>
        if ($analysis->query('//body//h1')) {
            return $this->pass();
        }
        return $this->fail($this->_t(__CLASS__ . '.Message', 'The page has no main heading (H1).'));
    }
}
