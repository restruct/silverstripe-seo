<?php

namespace Restruct\Silverstripe\SEO\Checks\Rules;

use Restruct\Silverstripe\SEO\Checks\AbstractCheck;
use Restruct\Silverstripe\SEO\Checks\CheckResult;
use Restruct\Silverstripe\SEO\Checks\PageAnalysis;
use SilverStripe\SiteConfig\SiteConfig;

/**
 * Check 3: a title that says nothing about the page: only the site name, the site name twice, or a placeholder
 * such as "New Page". These are among the reasons Google gives for rewriting a title.
 */
class TitleBoilerplateCheck extends AbstractCheck
{
    private static string $id = 'title-boilerplate';

    private static string $group = self::GROUP_TITLE;

    private static string $severity = self::WARNING;

    private static string $priority = self::HIGH;

    /**
     * Titles that are placeholders, compared case-insensitively with the page's own title part.
     */
    private static array $placeholder_titles = [
        'new page',
        'nieuwe pagina',
        'untitled',
        'naamloos',
        'title',
        'titel',
        'page',
        'pagina',
        'test',
    ];

    public function title(): string
    {
        return _t(__CLASS__ . '.Title', 'Title says what the page is about');
    }

    public function failTitle(): string
    {
        return _t(__CLASS__ . '.FailTitle', 'Title does not say what the page is about');
    }

    public function howToFix(): string
    {
        return _t(
            __CLASS__ . '.HowToFix',
            'Write a meta title that describes this page in a few words. The site name is added automatically; do not type it yourself.'
        );
    }

    public function run(PageAnalysis $analysis): CheckResult
    {
        $own = trim($analysis->pageTitle());
        $full = (string) $analysis->title();
        $config = SiteConfig::current_site_config();
        $siteName = trim((string) ($config->SeoTitleSuffix ?: $config->Title));

        if ($siteName !== '' && mb_strtolower($own) === mb_strtolower($siteName)) {
            return $this->fail($this->_t(__CLASS__ . '.IsSiteName', 'The title is just the site name: "{title}".', ['title' => $full]));
        }
        # Repeated: twice in the page's own title, or the own title already ends in the suffix that is appended
        # again. Once in the own title plus the suffix ("About Acme | Acme") is fine.
        if ($siteName !== '' && (static::occurrences($own, $siteName) > 1 || ($full !== $own && static::endsWithName($own, $siteName)))) {
            return $this->fail(
                $this->_t(__CLASS__ . '.Repeated', 'The site name is in the title more than once.'),
                [$full]
            );
        }
        $placeholders = array_map('mb_strtolower', (array) static::config()->get('placeholder_titles'));
        if (in_array(mb_strtolower($own), $placeholders, true)) {
            return $this->fail($this->_t(__CLASS__ . '.Placeholder', 'The title "{title}" is a placeholder.', ['title' => $own]));
        }
        return $this->pass();
    }

    /**
     * How often a name occurs as whole words (unicode-aware: a letter or digit on either side is not a boundary,
     * so "Shop" is not in "Workshop", and "Café" is matched as a word).
     */
    protected static function occurrences(string $text, string $name): int
    {
        $pattern = '/(?<![\p{L}\p{N}])' . preg_quote($name, '/') . '(?![\p{L}\p{N}])/iu';
        return (int) preg_match_all($pattern, $text);
    }

    /**
     * The own title ends in a separator and the name, as a hand-written suffix would.
     */
    protected static function endsWithName(string $text, string $name): bool
    {
        return (bool) preg_match('/[|\-:\x{2013}\x{2014}\x{00B7}]\s*' . preg_quote($name, '/') . '\s*$/iu', $text);
    }
}
