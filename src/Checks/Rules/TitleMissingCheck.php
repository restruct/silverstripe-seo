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

    /**
     * What the check looks at and why it matters, for the editor reading the "About this check" dialog.
     */
    public function about(): string
    {
        return _t(
            __CLASS__ . '.About',
            'Checks that the page has a title for search results: the clickable line of a result, which also shows on the browser tab. Without one, search engines make one up. This usually points at a problem in the website\'s templates, not in the page.'
        );
    }

    public function passTitle(): string
    {
        return _t(__CLASS__ . '.PassTitle', 'Page has a title for search results');
    }

    public function failTitle(): string
    {
        return _t(__CLASS__ . '.FailTitle', 'Page has no title for search results');
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
