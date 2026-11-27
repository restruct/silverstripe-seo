<?php

namespace Restruct\Silverstripe\SEO\Checks\Rules;

use Restruct\Silverstripe\SEO\Checks\AbstractCheck;
use Restruct\Silverstripe\SEO\Checks\CheckResult;
use Restruct\Silverstripe\SEO\Checks\PageAnalysis;

/**
 * Check 11: images in the content (blocks and template images included) without an alt attribute. An empty
 * alt (alt="") marks an image as decorative, as HTML defines it, and passes; whether a decorative image really
 * is decorative cannot be told from the markup.
 */
class ImageAltMissingCheck extends AbstractCheck
{
    private static string $id = 'image-alt-missing';

    private static string $group = self::GROUP_IMAGES;

    private static string $severity = self::WARNING;

    private static string $priority = self::HIGH;

    private static bool $needs_render = true;


    public function title(): string
    {
        return _t(__CLASS__ . '.Title', 'Images have alt text');
    }

    public function failTitle(): string
    {
        return _t(__CLASS__ . '.FailTitle', 'Images lack alt text');
    }

    public function howToFix(): string
    {
        return _t(
            __CLASS__ . '.HowToFix',
            'Describe each image in its alt text (in the editor: the image\'s "Alternative text"), the way you would to someone who cannot see it. Only a purely decorative image gets an empty alt text.'
        );
    }

    public function run(PageAnalysis $analysis): CheckResult
    {
        $missing = [];
        foreach ($analysis->query('.//img') as $img) {
            if (!$img->hasAttribute('alt')) {
                $missing[] = static::short($img->getAttribute('src') ?: $img->getAttribute('data-src'), 120);
            }
        }
        if (!$missing) {
            return $this->pass();
        }
        $listed = static::unique($missing);
        return $this->fail(
            $this->_t(__CLASS__ . '.Message', '{count} image(s) have no alt text.', ['count' => count($missing)]),
            $listed
        );
    }
}
