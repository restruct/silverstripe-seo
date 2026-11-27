<?php

namespace Restruct\Silverstripe\SEO\Checks\Rules;

use Restruct\Silverstripe\SEO\Checks\AbstractCheck;
use Restruct\Silverstripe\SEO\Checks\CheckResult;
use Restruct\Silverstripe\SEO\Checks\PageAnalysis;

/**
 * Check 8: the description (typed or derived) is very short or longer than a result shows. Advisory: there is no
 * limit, search engines cut a long snippet off and may replace a short one.
 */
class DescriptionLengthCheck extends AbstractCheck
{
    private static string $id = 'description-length';

    private static string $group = self::GROUP_DESCRIPTION;

    private static string $severity = self::TIP;

    private static string $priority = self::LOW;

    /**
     * Characters. The live counter in the CMS uses the same two numbers.
     */
    private static int $min_length = 70;

    private static int $max_length = 160;

    public function title(): string
    {
        return _t(__CLASS__ . '.Title', 'Meta description length');
    }

    /**
     * What the check looks at and why it matters, for the editor reading the "About this check" dialog.
     */
    public function about(): string
    {
        return _t(
            __CLASS__ . '.About',
            'Checks the length of the meta description, the short text that may appear under the title of a search result. A long text is cut off; a very short one is often replaced by a piece of the page that the search engine picks itself.'
        );
    }

    public function passTitle(): string
    {
        return _t(__CLASS__ . '.PassTitle', 'Meta description has a good length');
    }

    public function failTitle(): string
    {
        return _t(__CLASS__ . '.FailTitle', 'Meta description is too short or too long');
    }

    public function howToFix(): string
    {
        return _t(
            __CLASS__ . '.HowToFix',
            'Aim for one or two sentences, about {min} to {max} characters. The counter below the field shows the length as you type.',
            ['min' => (int) static::config()->get('min_length'), 'max' => (int) static::config()->get('max_length')]
        );
    }

    public function run(PageAnalysis $analysis): CheckResult
    {
        $description = $analysis->description();
        $length = mb_strlen($description);
        $min = (int) static::config()->get('min_length');
        $max = (int) static::config()->get('max_length');
        # No description at all is check 6's business
        if ($length === 0 || ($length >= $min && $length <= $max)) {
            return $this->pass();
        }
        $message = $length < $min
            ? $this->_t(__CLASS__ . '.Short', 'The description is {length} characters; {min} or more gives search engines something to show.', ['length' => $length, 'min' => $min])
            : $this->_t(__CLASS__ . '.Long', 'The description is {length} characters; search results show about {max}.', ['length' => $length, 'max' => $max]);
        return $this->fail($message, [static::short($description, 200)]);
    }
}
