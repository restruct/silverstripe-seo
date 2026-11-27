<?php

namespace Restruct\Silverstripe\SEO\Checks\Rules;

use Restruct\Silverstripe\SEO\Checks\AbstractCheck;
use Restruct\Silverstripe\SEO\Checks\CheckResult;
use Restruct\Silverstripe\SEO\Checks\PageAnalysis;

/**
 * Check 19: very little text in the content area. Information only: there is no word-count target (Google:
 * length alone does not matter), but a page with a handful of words gives search engines and visitors little to
 * go on. A contact page or a form can be fine as it is.
 */
class ThinContentCheck extends AbstractCheck
{
    private static string $id = 'thin-content';

    private static string $group = self::GROUP_CONTENT;

    private static string $severity = self::TIP;

    private static string $priority = self::LOW;

    private static bool $needs_render = true;

    /**
     * Below this many words the check speaks up. Not a target to write towards.
     */
    private static int $min_words = 50;

    public function title(): string
    {
        return _t(__CLASS__ . '.Title', 'Amount of text');
    }

    public function passTitle(): string
    {
        return _t(__CLASS__ . '.PassTitle', 'Page has enough text');
    }

    public function failTitle(): string
    {
        return _t(__CLASS__ . '.FailTitle', 'Page has very little text');
    }

    public function howToFix(): string
    {
        return _t(
            __CLASS__ . '.HowToFix',
            'Nothing to fix if the page does what it should. Otherwise, say in a few sentences what the visitor finds here and what they can do next.'
        );
    }

    public function run(PageAnalysis $analysis): CheckResult
    {
        $words = count(preg_split('/[^\p{L}\p{N}]+/u', $analysis->contentText(), -1, PREG_SPLIT_NO_EMPTY) ?: []);
        if ($words >= (int) static::config()->get('min_words')) {
            return $this->pass();
        }
        return $this->fail($this->_t(
            __CLASS__ . '.Message',
            'The page has {count} words of text. That can be fine (a contact page, a form), but gives search engines little to show.',
            ['count' => $words]
        ));
    }
}
