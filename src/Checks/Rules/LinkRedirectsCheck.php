<?php

namespace Restruct\Silverstripe\SEO\Checks\Rules;

use Restruct\Silverstripe\SEO\Checks\AbstractCheck;
use Restruct\Silverstripe\SEO\Checks\CheckResult;
use Restruct\Silverstripe\SEO\Checks\LinkResolver;
use Restruct\Silverstripe\SEO\Checks\PageAnalysis;
use SilverStripe\CMS\Model\RedirectorPage;

/**
 * Check 15: links that take a detour: to a redirector page (link to its target instead), to the old URL of a
 * renamed or moved page, or over http:// while the site itself runs on https (the visitor is redirected, or
 * leaves the secure connection).
 */
class LinkRedirectsCheck extends AbstractCheck
{
    private static string $id = 'link-redirects';

    private static string $group = self::GROUP_LINKS;

    private static string $severity = self::TIP;

    private static string $priority = self::LOW;

    private static bool $needs_render = true;

    private static int $max_listed = 10;

    public function title(): string
    {
        return _t(__CLASS__ . '.Title', 'Links go straight to their target');
    }

    public function howToFix(): string
    {
        return _t(
            __CLASS__ . '.HowToFix',
            'Link to the final page rather than to a redirecting one, and use https:// for sites that support it.'
        );
    }

    public function run(PageAnalysis $analysis): CheckResult
    {
        $issues = [];
        $https = $analysis->isHttpsSite();
        foreach ($analysis->query('.//a[@href]') as $link) {
            $href = trim((string) $link->getAttribute('href'));
            if ($https && stripos($href, 'http://') === 0) {
                $issues[] = _t(__CLASS__ . '.Insecure', '{href}: http:// on an https site', ['href' => static::short($href, 120)]);
                continue;
            }
            $target = $analysis->links()->resolve($href);
            if ($target['type'] === LinkResolver::OLD_URL) {
                $issues[] = _t(__CLASS__ . '.OldUrl', '{href}: an old address of {target}, which redirects', [
                    'href' => static::short($href, 120),
                    'target' => $target['record'] ? (string) $target['record']->Link() : '?',
                ]);
                continue;
            }
            if ($target['type'] === LinkResolver::PAGE && $target['record'] instanceof RedirectorPage) {
                $issues[] = _t(__CLASS__ . '.Redirector', '{href}: redirects to {target}', [
                    'href' => static::short($href, 120),
                    'target' => (string) $target['record']->redirectionLink(),
                ]);
            }
        }
        $issues = array_values(array_unique($issues));
        if (!$issues) {
            return $this->pass();
        }
        return $this->fail(
            _t(__CLASS__ . '.Message', '{count} link(s) go through a redirect.', ['count' => count($issues)]),
            array_slice($issues, 0, (int) static::config()->get('max_listed'))
        );
    }
}
