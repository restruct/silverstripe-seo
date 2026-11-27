<?php

namespace Restruct\Silverstripe\SEO\Checks\Rules;

use Restruct\Silverstripe\SEO\Checks\AbstractCheck;
use Restruct\Silverstripe\SEO\Checks\CheckResult;
use Restruct\Silverstripe\SEO\Checks\PageAnalysis;
use Restruct\Silverstripe\SEO\Text\TitleWidth;

/**
 * Check 5: the title is wider than a search result shows. Advisory: Google cuts it off rather than penalising
 * it, but the end of the title is then lost. The width is estimated per character ({@see TitleWidth}).
 */
class TitleWidthCheck extends AbstractCheck
{
    private static string $id = 'title-width';

    private static string $group = self::GROUP_TITLE;

    private static string $severity = self::TIP;

    private static string $priority = self::MEDIUM;

    /**
     * About the width of a desktop search result title.
     */
    private static int $max_pixels = 600;

    public function title(): string
    {
        return _t(__CLASS__ . '.Title', 'Title fits in a search result');
    }

    /**
     * What the check looks at and why it matters, for the editor reading the "About this check" dialog.
     */
    public function about(): string
    {
        return _t(
            __CLASS__ . '.About',
            'Checks that the title fits in a search result. Search results cut off titles that are too wide, so the end of a long title is not shown.'
        );
    }

    public function failTitle(): string
    {
        return _t(__CLASS__ . '.FailTitle', 'Title is too wide for a search result');
    }

    public function howToFix(): string
    {
        return _t(
            __CLASS__ . '.HowToFix',
            'Shorten the meta title, with the most important words first. The preview below the field shows where it is cut off.'
        );
    }

    public function run(PageAnalysis $analysis): CheckResult
    {
        $title = (string) $analysis->title();
        $max = (int) static::config()->get('max_pixels');
        $pixels = TitleWidth::singleton()->pixels($title);
        if ($pixels <= $max) {
            return $this->pass();
        }
        return $this->fail(
            $this->_t(__CLASS__ . '.Message', 'The title is about {pixels} px wide; search results show about {max} px.', [
                'pixels' => (int) round($pixels),
                'max' => $max,
            ]),
            [$title]
        );
    }
}
