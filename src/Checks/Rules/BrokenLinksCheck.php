<?php

namespace Restruct\Silverstripe\SEO\Checks\Rules;

use DOMElement;
use Restruct\Silverstripe\SEO\Checks\AbstractCheck;
use Restruct\Silverstripe\SEO\Checks\CheckResult;
use Restruct\Silverstripe\SEO\Checks\LinkResolver;
use Restruct\Silverstripe\SEO\Checks\PageAnalysis;

/**
 * Check 13: links and images in the content that point at a page or file that does not exist. A target that
 * exists only as a draft is not broken (it may go live together with this page); {@see UnpublishedLinksCheck}
 * reports those, as a warning.
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
            'Edit each listed link: choose the page or file again with the link tool, or remove the link.'
        );
    }

    public function run(PageAnalysis $analysis): CheckResult
    {
        $broken = $this->collect($analysis, false);
        if (!$broken) {
            return $this->pass();
        }
        return $this->fail(
            _t(__CLASS__ . '.Message', '{count} link(s) or image(s) do not work.', ['count' => count($broken)]),
            array_slice($broken, 0, (int) static::config()->get('max_listed'))
        );
    }

    /**
     * The links and images of the content that are broken, or (with $unpublished) that point at a draft-only
     * page or file, described for the details.
     *
     * @return string[]
     */
    protected function collect(PageAnalysis $analysis, bool $unpublished): array
    {
        $broken = [];
        $resolver = $analysis->links();

        foreach ($this->targets($analysis) as [$url, $label, $tracked]) {
            $type = $resolver->resolve($url)['type'];
            $draft = in_array($type, [LinkResolver::DRAFT_PAGE, LinkResolver::DRAFT_FILE], true);
            if ($unpublished) {
                if (!$tracked && $draft) {
                    $broken[] = $this->describe($url, $label, $this->reason($type));
                }
            } elseif ($tracked && str_contains($url, '#')) {
                # Link tracking also checks that the anchor of a link exists on the target page
                $broken[] = $this->describe($url, $label, _t(__CLASS__ . '.Anchor', 'the anchor is not on the target page'));
            } elseif ($tracked || $type === LinkResolver::EMPTY) {
                $broken[] = $this->describe($url, $label, _t(__CLASS__ . '.Deleted', 'the linked page or file no longer exists'));
            } elseif (LinkResolver::isBroken($type) && !$draft) {
                $broken[] = $this->describe($url, $label, $this->reason($type));
            }
        }
        return array_values(array_unique($broken));
    }

    /**
     * Every URL the content points at: link hrefs, image and media sources, and each candidate of a srcset
     * (img and picture/source), with a label and whether link tracking marked the element broken.
     *
     * @return array<int, array{0:string, 1:string, 2:bool}>
     */
    protected function targets(PageAnalysis $analysis): array
    {
        $targets = [];
        $xpath = './/a[@href] | .//img | .//source[@src or @srcset] | .//video[@src] | .//audio[@src]'
            . ' | .//*[contains(concat(" ", normalize-space(@class), " "), " ss-broken ")]';
        foreach ($analysis->query($xpath) as $element) {
            $tracked = in_array('ss-broken', preg_split('/\s+/', (string) $element->getAttribute('class')) ?: [], true);
            $label = $this->labelOf($element);
            if ($element->nodeName === 'a' || $element->hasAttribute('src') || $tracked) {
                $targets[] = [$this->urlOf($element), $label, $tracked];
            }
            foreach (static::srcsetURLs((string) $element->getAttribute('srcset')) as $url) {
                $targets[] = [$url, $label, false];
            }
        }
        return $targets;
    }

    /**
     * The URLs of a srcset ("a.webp 1x, b.webp 2x" or "a.jpg 400w, ..."), without their descriptors.
     *
     * @return string[]
     */
    public static function srcsetURLs(string $srcset): array
    {
        $urls = [];
        foreach (preg_split('/,\s+/', trim($srcset)) ?: [] as $candidate) {
            $url = trim((string) preg_split('/\s+/', trim($candidate))[0]);
            if ($url !== '') {
                $urls[] = $url;
            }
        }
        return $urls;
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
