<?php

namespace Restruct\Silverstripe\SEO\Checks\Rules;

use Restruct\Silverstripe\SEO\Checks\AbstractCheck;
use Restruct\Silverstripe\SEO\Checks\CheckResult;
use Restruct\Silverstripe\SEO\Checks\PageAnalysis;

/**
 * Check 10: more than one H1, or a heading level skipped in the content (an H2 followed by an H4). Google says
 * this does not matter for ranking; it is kept because it does matter for accessibility.
 */
class HeadingStructureCheck extends AbstractCheck
{
    private static string $id = 'heading-structure';

    private static string $group = self::GROUP_CONTENT;

    private static string $severity = self::TIP;

    private static string $priority = self::LOW;

    private static bool $needs_render = true;

    public function title(): string
    {
        return _t(__CLASS__ . '.Title', 'Heading structure');
    }

    /**
     * What the check looks at and why it matters, for the editor reading the "About this check" dialog.
     */
    public function about(): string
    {
        return _t(
            __CLASS__ . '.About',
            'Checks that the page has one main heading and that the headings follow each other in order, without skipping a level (a Heading 2 followed by a Heading 4). People using a screen reader move through a page by its headings, and gaps make that harder.'
        );
    }

    public function passTitle(): string
    {
        return _t(__CLASS__ . '.PassTitle', 'Headings are in a logical order');
    }

    public function failTitle(): string
    {
        return _t(__CLASS__ . '.FailTitle', 'Headings are not in a logical order');
    }

    public function howToFix(): string
    {
        return _t(
            __CLASS__ . '.HowToFix',
            'Use one Heading 1, then Heading 2 for sections and Heading 3 inside those. Pick headings by their place in the outline, not by their size: the look can be changed with a style.'
        );
    }

    public function run(PageAnalysis $analysis): CheckResult
    {
        $details = [];

        $h1s = $analysis->query('//body//h1');
        if (count($h1s) > 1) {
            foreach ($h1s as $h1) {
                $details[] = 'h1: ' . static::short($h1->textContent);
            }
        }

        # The outline of the content area; the first heading may follow the page's H1 (often outside <main>)
        $previous = 1;
        foreach ($analysis->query('.//h1|.//h2|.//h3|.//h4|.//h5|.//h6') as $heading) {
            $level = (int) substr($heading->nodeName, 1);
            if ($level > $previous + 1) {
                $details[] = $this->_t(__CLASS__ . '.Skipped', 'h{level} "{text}" follows h{previous}', [
                    'level' => $level,
                    'text' => static::short($heading->textContent),
                    'previous' => $previous,
                ]);
            }
            $previous = $level;
        }

        if (!$details) {
            return $this->pass();
        }
        $message = count($h1s) > 1
            ? $this->_t(__CLASS__ . '.MultipleH1', 'The page has {count} main headings (H1), or skips heading levels.', ['count' => count($h1s)])
            : $this->_t(__CLASS__ . '.SkippedLevels', 'The content skips heading levels.');
        return $this->fail($message, $details);
    }
}
