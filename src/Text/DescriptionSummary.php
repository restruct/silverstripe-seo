<?php

namespace Restruct\Silverstripe\SEO\Text;

use SilverStripe\View\Parsers\HTMLValue;
use SilverStripe\View\Parsers\ShortcodeParser;

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
     * Elements whose boundaries separate words, as a browser lays them out on separate lines.
     */
    protected const BLOCK_ELEMENTS = [
        'address', 'article', 'aside', 'blockquote', 'caption', 'dd', 'details', 'div', 'dl', 'dt', 'figcaption',
        'figure', 'footer', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'header', 'hr', 'li', 'main', 'nav', 'ol', 'p',
        'pre', 'section', 'summary', 'table', 'tbody', 'td', 'tfoot', 'th', 'thead', 'tr', 'ul',
    ];

    /**
     * @param string $html Field HTML, shortcodes and all
     * @param int $maxLength Maximum length in characters (not bytes)
     */
    public static function fromHtml(string $html, int $maxLength): string
    {
        if (trim($html) === '' || $maxLength < 1) {
            return '';
        }

        $text = '';
        $value = static::prepare($html);
        foreach ($value->query('//p') as $paragraph) {
            $candidate = static::normalise($paragraph->textContent);
            if ($candidate !== '') {
                $text = $candidate;
                break;
            }
        }
        # No paragraph markup at all (a div, a list, plain text): fall back to all of the text. Read from the DOM
        # as well (not strip_tags), so entities are decoded the same single time as in a paragraph
        if ($text === '' && ($body = $value->getBody())) {
            $text = static::normalise($body->textContent);
        }

        return static::truncate($text, $maxLength);
    }

    /**
     * All of the text of a block of HTML, without a length limit: shortcodes removed, words separated at line
     * breaks and block boundaries, entities decoded once. Used for HTML fields in structured data.
     */
    public static function plainText(string $html): string
    {
        if (trim($html) === '') {
            return '';
        }
        $body = static::prepare($html)->getBody();
        return $body ? static::normalise($body->textContent) : '';
    }

    /**
     * The HTML parsed, with registered shortcodes removed and a space at every word boundary a browser would show.
     */
    protected static function prepare(string $html): HTMLValue
    {
        # Shortcodes are not text: [image ...] would otherwise end up verbatim in the description. Only the
        # bracketed shortcode syntax is matched (a name directly after the bracket), and only names registered
        # with the active parser are removed, so ordinary text in brackets like "[1]" or "[sic]" is kept. The
        # handlers are not run: the text is all that is needed, and a handler may query or render.
        $parser = ShortcodeParser::get_active();
        $html = preg_replace_callback(
            '/\[\/?([a-z_][a-z0-9_]*)(?:[\s,][^\]]*)?\]/i',
            fn (array $match): string => $parser->registered(strtolower($match[1])) ? '' : $match[0],
            $html
        ) ?? $html;

        # A space at every line break and block boundary, so "one<br>two" and "<li>one</li><li>two</li>" do not
        # run their words together; normalise() collapses the extra spaces again
        $html = preg_replace(
            '#<(?:br|/?(?:' . implode('|', static::BLOCK_ELEMENTS) . '))\b[^>]*>#i',
            ' $0 ',
            $html
        ) ?? $html;

        return HTMLValue::create($html);
    }

    /**
     * Collapses whitespace, so lengths are counted as a reader sees the text. Entities are not decoded here: the
     * text comes from the DOM, which decoded them once already, and a second pass would turn text that reads
     * "&lt;b&gt;" into "<b>".
     */
    protected static function normalise(string $text): string
    {
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
