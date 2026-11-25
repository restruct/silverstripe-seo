<?php

namespace Restruct\Silverstripe\SEO\Checks\Rules;

use DOMElement;
use Restruct\Silverstripe\SEO\Checks\AbstractCheck;
use Restruct\Silverstripe\SEO\Checks\CheckResult;
use Restruct\Silverstripe\SEO\Checks\PageAnalysis;

/**
 * Check 11: images in the content (blocks and template images included) without alt text. An image marked as
 * decorative (alt="" together with role="presentation"/"none" or aria-hidden="true") is fine. An empty alt
 * without such a marker is reported: editors leave the field empty by accident far more often than on purpose.
 */
class ImageAltMissingCheck extends AbstractCheck
{
    private static string $id = 'image-alt-missing';

    private static string $group = self::GROUP_IMAGES;

    private static string $severity = self::WARNING;

    private static string $priority = self::HIGH;

    private static bool $needs_render = true;

    private static int $max_listed = 10;

    public function title(): string
    {
        return _t(__CLASS__ . '.Title', 'Images have alt text');
    }

    public function howToFix(): string
    {
        return _t(
            __CLASS__ . '.HowToFix',
            'Describe each image in its alt text (in the editor: the image\'s "Alternative text"), the way you would to someone who cannot see it. A purely decorative image can be marked as such by the developer.'
        );
    }

    public function run(PageAnalysis $analysis): CheckResult
    {
        $missing = [];
        foreach ($analysis->query('.//img') as $img) {
            if (static::isDecorative($img)) {
                continue;
            }
            if (!$img->hasAttribute('alt') || trim($img->getAttribute('alt')) === '') {
                $missing[] = static::short($img->getAttribute('src') ?: $img->getAttribute('data-src'), 120);
            }
        }
        if (!$missing) {
            return $this->pass();
        }
        $listed = array_slice(array_values(array_unique($missing)), 0, (int) static::config()->get('max_listed'));
        return $this->fail(
            _t(__CLASS__ . '.Message', '{count} image(s) have no alt text.', ['count' => count($missing)]),
            $listed
        );
    }

    /**
     * alt="" plus an explicit decorative marker.
     */
    public static function isDecorative(DOMElement $img): bool
    {
        if (!$img->hasAttribute('alt') || trim($img->getAttribute('alt')) !== '') {
            return false;
        }
        $role = strtolower(trim($img->getAttribute('role')));
        return in_array($role, ['presentation', 'none'], true)
            || strtolower(trim($img->getAttribute('aria-hidden'))) === 'true';
    }
}
