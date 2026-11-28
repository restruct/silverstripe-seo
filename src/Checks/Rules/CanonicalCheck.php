<?php

namespace Restruct\Silverstripe\SEO\Checks\Rules;

use Restruct\Silverstripe\SEO\Checks\AbstractCheck;
use Restruct\Silverstripe\SEO\Checks\CheckResult;
use Restruct\Silverstripe\SEO\Checks\PageAnalysis;

/**
 * Check 17: the canonical URL of the rendered page points at another URL (search engines then index that one
 * instead of this page), is not a valid absolute URL, or is given more than once with different values.
 * A page without a canonical passes: search engines then pick one themselves. A canonical page picked in the page
 * tree that is not published or no longer exists fails as well: the page then falls back to its own canonical.
 */
class CanonicalCheck extends AbstractCheck
{
    private static string $id = 'canonical';

    private static string $group = self::GROUP_INDEXING;

    private static string $severity = self::WARNING;

    private static string $priority = self::HIGH;

    private static bool $needs_render = true;

    public function title(): string
    {
        return _t(__CLASS__ . '.Title', 'Canonical URL');
    }

    /**
     * What the check looks at and why it matters, for the editor reading the "About this check" dialog.
     */
    public function about(): string
    {
        return _t(
            __CLASS__ . '.About',
            'A page can tell search engines which address is its main one (the canonical URL), so that copies of the same page are listed only once. This checks that the page names itself. If it names another page, search engines list that page instead of this one.'
        );
    }

    public function passTitle(): string
    {
        return _t(__CLASS__ . '.PassTitle', 'Canonical URL is right');
    }

    public function failTitle(): string
    {
        return _t(__CLASS__ . '.FailTitle', 'Canonical URL is wrong');
    }

    public function howToFix(): string
    {
        return _t(
            __CLASS__ . '.HowToFix',
            'Leave the canonical URL empty unless this page duplicates another one. If the template or another module prints a canonical too, ask the developer to keep only one.'
        );
    }

    public function run(PageAnalysis $analysis): CheckResult
    {
        # A canonical page picked in the tree that cannot be used: the page silently points to itself (or to its
        # canonical URL) instead, which is not what the editor chose
        $page = $analysis->getPage();
        [$state, $picked] = $page->hasMethod('canonicalPageState') ? $page->canonicalPageState() : ['', null];
        if ($state === 'unpublished') {
            return $this->fail(
                $this->_t(__CLASS__ . '.PageUnpublished', 'The page chosen as canonical page is not published, so this page does not point to it yet.'),
                [(string) $picked->Title],
                $this->_t(__CLASS__ . '.PageUnpublishedFix', 'Publish the chosen page, or choose another one under Canonical page.')
            );
        }
        if ($state === 'missing') {
            return $this->fail(
                $this->_t(__CLASS__ . '.PageMissing', 'The page chosen as canonical page no longer exists, so this page does not point to it.'),
                [],
                $this->_t(__CLASS__ . '.PageMissingFix', 'Choose another page under Canonical page, or empty the field.')
            );
        }

        $hrefs = array_values(array_filter(array_map(
            fn (array $link) => trim((string) ($link['href'] ?? '')),
            $analysis->head()->links('canonical')
        ), fn (string $href) => $href !== ''));
        if (!$hrefs) {
            return $this->pass();
        }
        if (count(array_unique($hrefs)) > 1) {
            return $this->fail($this->_t(__CLASS__ . '.Several', 'The page has more than one canonical URL.'), array_unique($hrefs));
        }

        $href = $hrefs[0];
        $parts = parse_url($href);
        if (!is_array($parts) || !in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true) || empty($parts['host'])) {
            return $this->fail($this->_t(__CLASS__ . '.Invalid', 'The canonical URL is not a complete URL (https://...).'), [$href]);
        }
        if (static::normalise($href) !== static::normalise($analysis->ownURL())) {
            return $this->fail(
                $this->_t(__CLASS__ . '.Elsewhere', 'The canonical URL points at another URL, so search engines will list that URL instead of this page.'),
                [$href]
            );
        }
        return $this->pass();
    }

    /**
     * Scheme and host case-insensitive, a trailing slash optional ("https://host" is the root "https://host/").
     * The scheme itself must match: the render takes the scheme of the site (PageRenderer's render request),
     * so an http canonical on an https site is a real mismatch, not an artefact of the render.
     */
    protected static function normalise(string $url): string
    {
        $parts = parse_url($url) ?: [];
        return strtolower(($parts['scheme'] ?? '') . '://' . ($parts['host'] ?? '') . (isset($parts['port']) ? ':' . $parts['port'] : ''))
            . rtrim((string) ($parts['path'] ?? ''), '/')
            . (isset($parts['query']) ? '?' . $parts['query'] : '');
    }
}
