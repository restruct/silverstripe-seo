<?php

namespace Restruct\Silverstripe\SEO\Checks\Rules;

use Restruct\Silverstripe\SEO\Checks\AbstractCheck;
use Restruct\Silverstripe\SEO\Checks\CheckResult;
use Restruct\Silverstripe\SEO\Checks\PageAnalysis;

/**
 * Check 17: the canonical URL of the rendered page points at another URL (search engines then index that one
 * instead of this page), is not a valid absolute URL, or is given more than once with different values.
 * A page without a canonical passes: search engines then pick one themselves.
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

    public function howToFix(): string
    {
        return _t(
            __CLASS__ . '.HowToFix',
            'Leave the canonical URL empty unless this page duplicates another one. If the template or another module prints a canonical too, ask the developer to keep only one.'
        );
    }

    public function run(PageAnalysis $analysis): CheckResult
    {
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
     * Scheme and host case-insensitive, a trailing slash optional.
     */
    protected static function normalise(string $url): string
    {
        $parts = parse_url($url) ?: [];
        return strtolower(($parts['scheme'] ?? '') . '://' . ($parts['host'] ?? '') . (isset($parts['port']) ? ':' . $parts['port'] : ''))
            . rtrim((string) ($parts['path'] ?? ''), '/')
            . (isset($parts['query']) ? '?' . $parts['query'] : '');
    }
}
