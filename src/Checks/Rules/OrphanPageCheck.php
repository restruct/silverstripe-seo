<?php

namespace Restruct\Silverstripe\SEO\Checks\Rules;

use Restruct\Silverstripe\SEO\Checks\AbstractCheck;
use Restruct\Silverstripe\SEO\Checks\CheckResult;
use Restruct\Silverstripe\SEO\Checks\PageAnalysis;
use SilverStripe\CMS\Controllers\RootURLController;

/**
 * Check 16: nothing on the site leads to the page: it is not in the menus (or sits below a page that is not),
 * and no other page or block links to it. Search engines find pages by following links; an orphan is found only
 * through the sitemap, and is treated as unimportant.
 *
 * Links printed by templates (a footer, a hand-made menu) cannot be seen, so this is a warning to look at rather
 * than a certainty; ignore it for pages that are linked that way.
 */
class OrphanPageCheck extends AbstractCheck
{
    private static string $id = 'orphan-page';

    private static string $group = self::GROUP_LINKS;

    private static string $severity = self::WARNING;

    private static string $priority = self::MEDIUM;

    public function title(): string
    {
        return _t(__CLASS__ . '.Title', 'Other pages link to this page');
    }

    public function howToFix(): string
    {
        return _t(
            __CLASS__ . '.HowToFix',
            'Link to this page from related pages, or show it in the menu. If the template links to it (a footer link, say), ignore this check for the page.'
        );
    }

    public function run(PageAnalysis $analysis): CheckResult
    {
        $page = $analysis->getPage();
        if ($page->URLSegment === RootURLController::get_homepage_link() && !$page->ParentID) {
            return $this->pass();
        }
        if ($analysis->site()->isInMenu($page) || $analysis->site()->inboundLinkCount($page) > 0) {
            return $this->pass();
        }
        return $this->fail(_t(
            __CLASS__ . '.Message',
            'No other page links to this page, and it is not in the menu.'
        ));
    }
}
