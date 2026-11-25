<?php

namespace Restruct\Silverstripe\SEO\Checks\Rules;

use Restruct\Silverstripe\SEO\Checks\AbstractCheck;
use Restruct\Silverstripe\SEO\Checks\CheckResult;
use Restruct\Silverstripe\SEO\Checks\PageAnalysis;

/**
 * Check 23: a URL segment that is harder to read than it needs to be: underscores (search engines treat a
 * hyphen as a word break, an underscore not), very long, or full of small words.
 *
 * Changing the URL of a published page changes its address (Silverstripe redirects the old one), so this is a
 * tip for new pages more than a reason to rename old ones.
 */
class UrlSegmentCheck extends AbstractCheck
{
    private static string $id = 'url-segment';

    private static string $group = self::GROUP_URL;

    private static string $severity = self::TIP;

    private static string $priority = self::LOW;

    /**
     * Characters of the page's own segment.
     */
    private static int $max_length = 60;

    /**
     * This many stop words in the segment makes it worth shortening.
     */
    private static int $stop_words_min = 2;

    /**
     * Per language; all lists apply.
     */
    private static array $stop_words = [
        'en' => ['a', 'an', 'the', 'and', 'or', 'of', 'to', 'in', 'on', 'for', 'with', 'at', 'by', 'from', 'is'],
        'nl' => ['de', 'het', 'een', 'en', 'of', 'van', 'voor', 'in', 'op', 'met', 'aan', 'bij', 'te', 'naar', 'is'],
    ];

    public function title(): string
    {
        return _t(__CLASS__ . '.Title', 'Readable URL');
    }

    public function howToFix(): string
    {
        return _t(
            __CLASS__ . '.HowToFix',
            'Use a few words that describe the page, separated by hyphens. For a page that is already published, think twice: links to the old URL are redirected, but the address changes.'
        );
    }

    public function run(PageAnalysis $analysis): CheckResult
    {
        $segment = (string) $analysis->getPage()->URLSegment;
        $issues = [];
        if (str_contains($segment, '_')) {
            $issues[] = _t(__CLASS__ . '.Underscore', 'contains underscores (_); hyphens (-) separate words');
        }
        $max = (int) static::config()->get('max_length');
        if (mb_strlen($segment) > $max) {
            $issues[] = _t(__CLASS__ . '.Long', '{length} characters; {max} or fewer reads better', [
                'length' => mb_strlen($segment),
                'max' => $max,
            ]);
        }
        $stop = [];
        foreach ((array) static::config()->get('stop_words') as $list) {
            foreach ((array) $list as $word) {
                $stop[mb_strtolower((string) $word)] = true;
            }
        }
        $found = array_values(array_filter(
            preg_split('/[\-_]+/', mb_strtolower($segment), -1, PREG_SPLIT_NO_EMPTY) ?: [],
            fn (string $word) => isset($stop[$word])
        ));
        if (count($found) >= (int) static::config()->get('stop_words_min')) {
            $issues[] = _t(__CLASS__ . '.StopWords', 'small words that add little: {words}', ['words' => implode(', ', array_unique($found))]);
        }
        if (!$issues) {
            return $this->pass();
        }
        return $this->fail(
            _t(__CLASS__ . '.Message', 'The URL segment "{segment}" could be easier to read.', ['segment' => $segment]),
            $issues
        );
    }
}
