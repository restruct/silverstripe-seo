<?php

namespace Restruct\Silverstripe\SEO\Text;

use SilverStripe\View\Parsers\HTMLValue;

/**
 * Turns a block of HTML into a short plain-text description: the first paragraph that has any text, cut at a word
 * boundary.
 *
 * This is the simple fallback for pages without a typed meta description. It reads a field's HTML (Content),
 * not the rendered page, so it cannot see Elemental blocks or template text; a later version feeds the rendered
 * page in through the `updateSeoDescriptionFallback` hook instead.
 */
class DescriptionSummary
{
    /**
     * @param string $html Field HTML, shortcodes and all
     * @param int $maxLength Maximum length in characters (not bytes)
     */
    public static function fromHtml(string $html, int $maxLength): string
    {
        if (trim($html) === '' || $maxLength < 1) {
            return '';
        }

        # Shortcodes are not text: [image ...] would otherwise end up verbatim in the description. Only the
        # bracketed shortcode syntax is matched (a name directly after the bracket), so ordinary text in
        # brackets like "[1]" is kept.
        $html = preg_replace('/\[\/?[a-z_][a-z0-9_]*(?:[\s,][^\]]*)?\]/i', '', $html) ?? $html;

        $text = '';
        $value = HTMLValue::create($html);
        foreach ($value->query('//p') as $paragraph) {
            $candidate = static::normalise($paragraph->textContent);
            if ($candidate !== '') {
                $text = $candidate;
                break;
            }
        }
        # No paragraph markup at all (a div, a list, plain text): fall back to all of the text
        if ($text === '') {
            $text = static::normalise(strip_tags($html));
        }

        return static::truncate($text, $maxLength);
    }

    /**
     * Collapses whitespace and decodes entities, so lengths are counted as a reader sees the text.
     */
    protected static function normalise(string $text): string
    {
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        # \s with the u flag also matches non-breaking spaces
        return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    }

    /**
     * Cuts at the last whole word that fits. No ellipsis is added: search engines mark a cut themselves, and an
     * added character would count against the length.
     */
    protected static function truncate(string $text, int $maxLength): string
    {
        if (mb_strlen($text) <= $maxLength) {
            return $text;
        }

        $cut = mb_substr($text, 0, $maxLength + 1);
        $lastSpace = mb_strrpos($cut, ' ');
        # A single word longer than the limit: a hard cut is the only option left
        $cut = $lastSpace === false ? mb_substr($text, 0, $maxLength) : mb_substr($cut, 0, $lastSpace);

        # A cut right after a comma or a dash reads as a broken sentence
        return rtrim($cut, " ,;:-");
    }
}
