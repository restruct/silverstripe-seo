<?php

namespace Restruct\Silverstripe\SEO\Checks\Rules;

use Restruct\Silverstripe\SEO\Checks\CheckResult;
use Restruct\Silverstripe\SEO\Checks\PageAnalysis;

/**
 * Check 13b: links and images in the content that point at a page or file that exists, but only as a draft.
 * Not broken yet (the target may be published together with this page), but it breaks for visitors if it is
 * not. Split off from {@see BrokenLinksCheck}, which keeps the links that are broken for certain.
 */
class UnpublishedLinksCheck extends BrokenLinksCheck
{
    private static string $id = 'unpublished-links';

    private static string $severity = self::WARNING;

    private static string $priority = self::MEDIUM;

    public function title(): string
    {
        return _t(__CLASS__ . '.Title', 'Link targets are published');
    }

    public function howToFix(): string
    {
        return _t(
            __CLASS__ . '.HowToFix',
            'Publish the listed pages and files together with this page (or before it), or link to something that is live.'
        );
    }

    public function run(PageAnalysis $analysis): CheckResult
    {
        $unpublished = $this->collect($analysis, true);
        if (!$unpublished) {
            return $this->pass();
        }
        return $this->fail(
            _t(__CLASS__ . '.Message', '{count} link(s) or image(s) point at a page or file that is not published yet.', ['count' => count($unpublished)]),
            array_slice($unpublished, 0, (int) static::config()->get('max_listed'))
        );
    }
}
