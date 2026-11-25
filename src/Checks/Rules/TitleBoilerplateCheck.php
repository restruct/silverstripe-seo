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
            return $this->fail(_t(__CLASS__ . '.IsSiteName', 'The title is just the site name: "{title}".', ['title' => $full]));
        }
        if ($siteName !== '' && mb_substr_count(mb_strtolower($full), mb_strtolower($siteName)) > 1) {
            return $this->fail(
                _t(__CLASS__ . '.Repeated', 'The site name is in the title more than once.'),
                [$full]
            );
        }
        $placeholders = array_map('mb_strtolower', (array) static::config()->get('placeholder_titles'));
        if (in_array(mb_strtolower($own), $placeholders, true)) {
            return $this->fail(_t(__CLASS__ . '.Placeholder', 'The title "{title}" is a placeholder.', ['title' => $own]));
        }
        return $this->pass();
    }
}
