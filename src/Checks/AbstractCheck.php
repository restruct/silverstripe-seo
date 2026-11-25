<?php

namespace Restruct\Silverstripe\SEO\Checks;

use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Injector\Injectable;

/**
 * Base for checks: id, group, severity and priority are config, so a project can change a check's severity or
 * priority in YAML (`Restruct\Silverstripe\SEO\Checks\Rules\ThinContentCheck: { priority: medium }`) as well as
 * its thresholds, which each check declares as config of its own.
 */
abstract class AbstractCheck implements Check
{
    use Configurable;
    use Injectable;

    private static string $id = '';

    private static string $group = self::GROUP_CONTENT;

    private static string $severity = self::TIP;

    private static string $priority = self::LOW;

    /**
     * Whether the check reads the rendered page; see {@see Check::needsRender()}.
     */
    private static bool $needs_render = false;

    /**
     * Whether the check also runs on a page that asks not to be indexed. Only the noindex check does: the other
     * checks are about how the page shows up in search results, which a noindex page does not.
     */
    private static bool $runs_on_noindex = false;

    public function id(): string
    {
        return (string) static::config()->get('id');
    }

    public function group(): string
    {
        return (string) static::config()->get('group');
    }

    public function severity(): string
    {
        return (string) static::config()->get('severity');
    }

    public function priority(): string
    {
        return (string) static::config()->get('priority');
    }

    public function needsRender(): bool
    {
        return (bool) static::config()->get('needs_render');
    }

    protected function pass(): CheckResult
    {
        return CheckResult::pass();
    }

    /**
     * @param string[] $details
     */
    protected function fail(string $message, array $details = [], string $howToFix = ''): CheckResult
    {
        return CheckResult::fail($message, $details, $howToFix);
    }

    /**
     * A shortened string for details: long alt texts, link texts and URLs are cut at $max characters.
     */
    protected static function short(string $text, int $max = 80): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));
        return mb_strlen($text) > $max ? rtrim(mb_substr($text, 0, $max - 3)) . '...' : $text;
    }
}
