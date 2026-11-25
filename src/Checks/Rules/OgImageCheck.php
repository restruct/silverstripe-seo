<?php

namespace Restruct\Silverstripe\SEO\Checks\Rules;

use Restruct\Silverstripe\SEO\Checks\AbstractCheck;
use Restruct\Silverstripe\SEO\Checks\CheckResult;
use Restruct\Silverstripe\SEO\Checks\PageAnalysis;

/**
 * Check 21: no sharing image: not on the page, not from a project fallback, not the site default. Shared links
 * then show without a picture, or with one the other site picks.
 */
class OgImageCheck extends AbstractCheck
{
    private static string $id = 'og-image';

    private static string $group = self::GROUP_SOCIAL;

    private static string $severity = self::TIP;

    private static string $priority = self::LOW;

    public function title(): string
    {
        return _t(__CLASS__ . '.Title', 'Sharing image');
    }

    public function howToFix(): string
    {
        return _t(
            __CLASS__ . '.HowToFix',
            'Choose a sharing image for the page (1200 x 630 pixels works best), or set a default one for the whole site under Settings.'
        );
    }

    public function run(PageAnalysis $analysis): CheckResult
    {
        if ($analysis->isRendered()) {
            $found = trim((string) $analysis->head()->meta('og:image')) !== '';
        } else {
            $found = $analysis->getPage()->hasMethod('SeoImage') && $analysis->getPage()->SeoImage() !== null;
        }
        return $found
            ? $this->pass()
            : $this->fail(_t(__CLASS__ . '.Message', 'The page has no sharing image, and there is no site default.'));
    }
}
