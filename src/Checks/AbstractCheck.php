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

    /**
     * At most this many details are listed for a result, then "and N more". Set it per check class to differ.
     *
     * @config
     */
    private static int $max_listed = 20;

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
     * @param string|array $message A message from _t() (translated when shown) or plain text
     * @param array<int, string|array> $details Plain data (a URL, an alt text) or messages
     */
    protected function fail(string|array $message, array $details = [], string|array $howToFix = ''): CheckResult
    {
        return CheckResult::fail($message, $this->listed($details), $howToFix);
    }

    /**
     * A link to this check's documentation for the panel; '' for none. The module's own checks link to their
     * spec in the module's docs ({@see CheckRunner::$docs_url}); a project's check can return its own page.
     */
    public function docsUrl(): string
    {
        return '';
    }

    public static function maxListed(): int
    {
        return max(1, (int) static::config()->get('max_listed'));
    }

    /**
     * The details capped at max_listed, plus "and N more" for the rest. $total is the full number when the list
     * was already limited (in a query); a list that already ends in "and N more" is left alone.
     *
     * @param array<int, string|array> $details
     * @return array<int, string|array>
     */
    protected function listed(array $details, ?int $total = null): array
    {
        $details = array_values($details);
        $last = end($details);
        if (CheckMessage::isMessage($last) && $last['_t'] === AbstractCheck::class . '.AndMore') {
            return $details;
        }
        $max = static::maxListed();
        $total ??= count($details);
        if ($total <= $max) {
            return $details;
        }
        $details = array_slice($details, 0, $max);
        $details[] = CheckMessage::_t(AbstractCheck::class . '.AndMore', 'and {count} more', ['count' => $total - $max]);
        return $details;
    }

    /**
     * A message to store with a result, translated when it is shown ({@see CheckMessage}). Written like a
     * normal _t() call, so the text collector finds it.
     *
     * @return array{_t: string, default: string, params: array<string, mixed>}
     */
    protected function _t(string $key, string $default, array $params = []): array
    {
        return CheckMessage::_t($key, $default, $params);
    }

    /**
     * Without duplicates, also when the items are messages (arrays).
     */
    protected static function unique(array $items): array
    {
        return array_values(array_unique($items, SORT_REGULAR));
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
