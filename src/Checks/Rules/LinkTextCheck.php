<?php

namespace Restruct\Silverstripe\SEO\Checks\Rules;

use DOMElement;
use Restruct\Silverstripe\SEO\Checks\AbstractCheck;
use Restruct\Silverstripe\SEO\Checks\CheckResult;
use Restruct\Silverstripe\SEO\Checks\PageAnalysis;

/**
 * Check 14: links in the content without text, or with text that does not say where they go ("click here",
 * "lees meer"). Search engines use the link text to understand the target; screen reader users often list the
 * links of a page out of context.
 *
 * The text of a link is its own text, the alt text of an image inside it, or its aria-label / title.
 */
class LinkTextCheck extends AbstractCheck
{
    private static string $id = 'link-text';

    private static string $group = self::GROUP_LINKS;

    private static string $severity = self::TIP;

    private static string $priority = self::MEDIUM;

    private static bool $needs_render = true;

    /**
     * Link texts that say nothing about the target, keyed by language so a project can extend or replace one
     * list. All lists apply to every page, since a page in one language often has links copied from another.
     * Compared case-insensitively, with punctuation ignored.
     */
    private static array $non_descriptive_texts = [
        'en' => [
            'click here', 'click', 'here', 'read more', 'more', 'more info', 'more information', 'learn more',
            'link', 'this link', 'this page', 'continue', 'go', 'details', 'see more', 'view more',
        ],
        'nl' => [
            'klik hier', 'klik', 'hier', 'lees meer', 'meer', 'meer info', 'meer informatie', 'verder lezen',
            'lees verder', 'link', 'deze link', 'deze pagina', 'ga verder', 'details', 'bekijk', 'bekijk meer',
        ],
    ];


    public function title(): string
    {
        return _t(__CLASS__ . '.Title', 'Link text says where it goes');
    }

    public function failTitle(): string
    {
        return _t(__CLASS__ . '.FailTitle', 'Link text does not say where it goes');
    }

    public function howToFix(): string
    {
        return _t(
            __CLASS__ . '.HowToFix',
            'Make the link text say where the link goes ("our course timetable" rather than "click here"). For a link that is only an image, describe the target in the image\'s alt text.'
        );
    }

    public function run(PageAnalysis $analysis): CheckResult
    {
        $generic = $this->genericTexts();
        $issues = [];
        foreach ($analysis->query('.//a[@href]') as $link) {
            $href = static::short((string) $link->getAttribute('href'), 100);
            # The accessible name when the link sets one; screen readers and search engines read that instead
            $text = $this->accessibleNameOf($link) ?: $this->textOf($link);
            if ($text === '') {
                $issues[] = $this->_t(__CLASS__ . '.Empty', 'A link without text, to {href}', ['href' => $href]);
                continue;
            }
            $normalised = mb_strtolower(trim((string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', $text)));
            if (isset($generic[$normalised])) {
                $issues[] = sprintf('"%s" (%s)', static::short($text, 60), $href);
            }
        }
        if (!$issues) {
            return $this->pass();
        }
        return $this->fail(
            $this->_t(__CLASS__ . '.Message', '{count} link(s) have no text, or text that does not say where they go.', ['count' => count($issues)]),
            $issues
        );
    }

    /**
     * aria-labelledby (the text of the referenced elements), aria-label or title, in that order; '' when none
     * is set.
     */
    protected function accessibleNameOf(DOMElement $link): string
    {
        $ids = preg_split('/\s+/', trim((string) $link->getAttribute('aria-labelledby'))) ?: [];
        $parts = [];
        if ($link->ownerDocument && array_filter($ids)) {
            $xpath = new \DOMXPath($link->ownerDocument);
            foreach (array_filter($ids) as $id) {
                # An id with a quote cannot be an XPath literal here; skip it rather than build a broken query
                if (str_contains($id, '"')) {
                    continue;
                }
                foreach ($xpath->query('//*[@id="' . $id . '"]') ?: [] as $node) {
                    $parts[] = trim((string) $node->textContent);
                }
            }
        }
        foreach ([implode(' ', array_filter($parts)), $link->getAttribute('aria-label'), $link->getAttribute('title')] as $name) {
            $name = trim((string) preg_replace('/\s+/u', ' ', (string) $name));
            if ($name !== '') {
                return $name;
            }
        }
        return '';
    }

    protected function textOf(DOMElement $link): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', $link->textContent));
        if ($text === '') {
            foreach ($link->getElementsByTagName('img') as $img) {
                $text .= ' ' . trim($img->getAttribute('alt'));
            }
            $text = trim($text);
        }
        if ($text === '') {
            $text = trim($link->getAttribute('aria-label') ?: $link->getAttribute('title'));
        }
        return $text;
    }

    /**
     * @return array<string, true>
     */
    protected function genericTexts(): array
    {
        $set = [];
        foreach ((array) static::config()->get('non_descriptive_texts') as $list) {
            foreach ((array) $list as $text) {
                $set[mb_strtolower(trim((string) $text))] = true;
            }
        }
        return $set;
    }
}
