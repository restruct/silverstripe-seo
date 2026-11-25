<?php

namespace Restruct\Silverstripe\SEO\Checks\Rules;

use DOMElement;
use Restruct\Silverstripe\SEO\Checks\AbstractCheck;
use Restruct\Silverstripe\SEO\Checks\CheckResult;
use Restruct\Silverstripe\SEO\Checks\LinkResolver;
use Restruct\Silverstripe\SEO\Checks\PageAnalysis;

/**
 * Check 13: links and images in the content that point at a page or file that does not exist, or exists only as
 * a draft (it breaks for visitors once this page is published).
 *
 * Two sources, both without any HTTP request:
 * - core's link tracking marks a link shortcode to a deleted page or file, and a link to an anchor that is not
 *   on the target page, with the class `ss-broken` when the record is saved; a link to a deleted page renders
 *   with an empty href; all of these are reported;
 * - every other internal href and src is resolved against the pages and files in the database
 *   ({@see LinkResolver}). External links are not checked.
 */
class BrokenLinksCheck extends AbstractCheck
{
    private static string $id = 'broken-links';

    private static string $group = self::GROUP_LINKS;

    private static string $severity = self::PROBLEM;

    private static string $priority = self::HIGH;

    private static bool $needs_render = true;

    private static int $max_listed = 15;

    public function title(): string
    {
        return _t(__CLASS__ . '.Title', 'Links and files work');
    }

    public function howToFix(): string
    {
        return _t(
            __CLASS__ . '.HowToFix',
            'Edit each listed link: choose the page or file again with the link tool, or remove the link. A page or file that is only a draft needs publishing first.'
        );
    }

    public function run(PageAnalysis $analysis): CheckResult
    {
        $broken = [];
        $resolver = $analysis->links();

        foreach ($analysis->query('.//a[@href] | .//img | .//source[@src] | .//*[contains(concat(" ", normalize-space(@class), " "), " ss-broken ")]') as $element) {
            $url = $this->urlOf($element);
            $label = $this->labelOf($element);
            $tracked = in_array('ss-broken', preg_split('/\s+/', (string) $element->getAttribute('class')) ?: [], true);
            $type = $resolver->resolve($url)['type'];

            if ($tracked && str_contains($url, '#')) {
                # Link tracking also checks that the anchor of a link exists on the target page
                $broken[] = $this->describe($url, $label, _t(__CLASS__ . '.Anchor', 'the anchor is not on the target page'));
            } elseif ($tracked || ($element->nodeName === 'a' && $type === LinkResolver::EMPTY)) {
                $broken[] = $this->describe($url, $label, _t(__CLASS__ . '.Deleted', 'the linked page or file no longer exists'));
            } elseif (LinkResolver::isBroken($type)) {
                $broken[] = $this->describe($url, $label, $this->reason($type));
            }
        }

        $broken = array_values(array_unique($broken));
        if (!$broken) {
            return $this->pass();
        }
        return $this->fail(
            _t(__CLASS__ . '.Message', '{count} link(s) or image(s) do not work.', ['count' => count($broken)]),
            array_slice($broken, 0, (int) static::config()->get('max_listed'))
        );
    }

    protected function urlOf(DOMElement $element): string
    {
        return (string) ($element->nodeName === 'a' ? $element->getAttribute('href') : $element->getAttribute('src'));
    }

    protected function labelOf(DOMElement $element): string
    {
        if ($element->nodeName === 'img') {
            return trim($element->getAttribute('alt'));
        }
        return trim((string) preg_replace('/\s+/u', ' ', $element->textContent));
    }

    protected function reason(string $type): string
    {
        return match ($type) {
            LinkResolver::DRAFT_PAGE => _t(__CLASS__ . '.DraftPage', 'the page is not published'),
            LinkResolver::DRAFT_FILE => _t(__CLASS__ . '.DraftFile', 'the file is not published'),
            LinkResolver::MISSING_FILE => _t(__CLASS__ . '.MissingFile', 'no such file'),
            default => _t(__CLASS__ . '.MissingPage', 'no such page'),
        };
    }

    protected function describe(string $url, string $label, string $reason): string
    {
        $url = $url === '' ? _t(__CLASS__ . '.EmptyHref', '(empty link)') : static::short($url, 120);
        return $label !== ''
            ? sprintf('"%s" %s: %s', static::short($label, 60), $url, $reason)
            : sprintf('%s: %s', $url, $reason);
    }
}
