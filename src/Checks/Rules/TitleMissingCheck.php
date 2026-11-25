<?php

namespace Restruct\Silverstripe\SEO\Checks\Rules;

use Restruct\Silverstripe\SEO\Checks\AbstractCheck;
use Restruct\Silverstripe\SEO\Checks\CheckResult;
use Restruct\Silverstripe\SEO\Checks\PageAnalysis;

/**
 * Check 2: the rendered page has no `<title>`, or an empty one. With the module's head output the title falls
 * back to the page title plus the site name, so this mostly catches a template without `$MetaTags` or a page
 * without any title at all.
 */
class TitleMissingCheck extends AbstractCheck
{
    private static string $id = 'title-missing';

    private static string $group = self::GROUP_TITLE;

    private static string $severity = self::PROBLEM;

    private static string $priority = self::HIGH;

    public function title(): string
    {
        return _t(__CLASS__ . '.Title', 'Page title in search results');
    }

    public function howToFix(): string
    {
        return _t(
            __CLASS__ . '.HowToFix',
            'Give the page a title (or a meta title). If it has one, the page template does not print it: the template needs $MetaTags (or a <title> element) in its <head>.'
        );
    }

    public function run(PageAnalysis $analysis): CheckResult
    {
        $title = $analysis->title();
        if ($title === null) {
            return $this->fail($this->_t(__CLASS__ . '.NoElement', 'The page has no <title> element.'));
        }
        if (trim($title) === '') {
            return $this->fail($this->_t(__CLASS__ . '.Empty', 'The page title is empty.'));
        }
        return $this->pass();
    }
}
