<?php

namespace Restruct\Silverstripe\SEO\Tests\Stub\Report;

use Restruct\Silverstripe\SEO\Checks\AbstractCheck;
use Restruct\Silverstripe\SEO\Checks\CheckResult;
use Restruct\Silverstripe\SEO\Checks\PageAnalysis;
use SilverStripe\Dev\TestOnly;
use SilverStripe\Security\Security;

/**
 * Records, per page, who the checks ran as and what the render looked like, so a test can tell whether a site
 * run rendered as a visitor or as whoever happened to be logged in.
 */
class SeoRenderSpyTestCheck extends AbstractCheck implements TestOnly
{
    private static string $id = 'render-spy';

    private static string $group = self::GROUP_CONTENT;

    private static string $severity = self::TIP;

    private static string $priority = self::LOW;

    /**
     * @var array<int, array{member:int, status:int, cmsMeta:bool}> Keyed by page ID
     */
    public static array $seen = [];

    public function title(): string
    {
        return 'Render spy';
    }

    public function howToFix(): string
    {
        return '';
    }

    public function run(PageAnalysis $analysis): CheckResult
    {
        $render = $analysis->getRender();
        static::$seen[(int) $analysis->getPage()->ID] = [
            'member' => (int) Security::getCurrentUser()?->ID,
            'status' => $render ? $render->getStatusCode() : 0,
            # SiteTree::MetaTags() adds x-page-id only for a member with CMS access
            'cmsMeta' => $render !== null && str_contains($render->getHtml(), 'x-page-id'),
        ];
        return $this->pass();
    }
}
