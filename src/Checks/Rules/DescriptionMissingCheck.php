<?php

namespace Restruct\Silverstripe\SEO\Checks\Rules;

use Restruct\Silverstripe\SEO\Checks\AbstractCheck;
use Restruct\Silverstripe\SEO\Checks\CheckResult;
use Restruct\Silverstripe\SEO\Checks\PageAnalysis;
use Restruct\Silverstripe\SEO\Text\DescriptionSummary;

/**
 * Check 6: no meta description was typed, and the one derived from the content is missing or too short to be of
 * use. Soft on purpose: search engines often write their own snippet anyway, but a good fallback helps. When the
 * text is in blocks (which the field-based fallback cannot see), a suggestion is taken from the rendered page.
 */
class DescriptionMissingCheck extends AbstractCheck
{
    private static string $id = 'description-missing';

    private static string $group = self::GROUP_DESCRIPTION;

    private static string $severity = self::WARNING;

    private static string $priority = self::MEDIUM;

    /**
     * A derived description shorter than this (in characters) counts as weak.
     */
    private static int $weak_fallback_length = 50;

    /**
     * Length of the suggestion taken from the rendered page.
     */
    private static int $suggestion_length = 155;

    public function title(): string
    {
        return _t(__CLASS__ . '.Title', 'Meta description');
    }

    public function howToFix(): string
    {
        return _t(
            __CLASS__ . '.HowToFix',
            'Write a meta description: one or two sentences saying what the visitor finds on this page.'
        );
    }

    public function run(PageAnalysis $analysis): CheckResult
    {
        if ($analysis->typedDescription() !== '') {
            return $this->pass();
        }
        $fallback = $analysis->description();
        if (mb_strlen($fallback) >= (int) static::config()->get('weak_fallback_length')) {
            return $this->pass();
        }

        $details = [];
        $content = $analysis->content();
        if ($content) {
            $suggestion = DescriptionSummary::fromHtml($content->getHtml(), (int) static::config()->get('suggestion_length'));
            if ($suggestion !== '' && $suggestion !== $fallback) {
                $details[] = _t(__CLASS__ . '.Suggestion', 'From the page: {text}', ['text' => $suggestion]);
            }
        }
        $message = $fallback === ''
            ? _t(__CLASS__ . '.Missing', 'There is no meta description, and none can be derived from the page content.')
            : _t(__CLASS__ . '.Weak', 'There is no meta description; the one derived from the content is short: "{text}".', ['text' => $fallback]);
        return $this->fail($message, $details);
    }
}
