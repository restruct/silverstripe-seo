<?php

namespace Restruct\Silverstripe\SEO\Checks\Rules;

use Restruct\Silverstripe\SEO\Checks\AbstractCheck;
use Restruct\Silverstripe\SEO\Checks\CheckResult;
use Restruct\Silverstripe\SEO\Checks\PageAnalysis;
use SilverStripe\CMS\Controllers\RootURLController;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Core\Extensible;

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
    use Extensible;

    private static string $id = 'orphan-page';

    private static string $group = self::GROUP_LINKS;

    private static string $severity = self::WARNING;

    private static string $priority = self::MEDIUM;

    # Reads other pages (their titles, descriptions, links or link targets): stale when any page changes
    private static bool $cross_page = true;

    /**
     * Page classes whose template lists their children (a blog holder, a news archive, an overview page): a
     * child of such a parent is linked from it, as long as the parent itself is reachable (in the menu, or
     * linked from another page). Subclasses count. Empty by default; for example:
     *
     *     Restruct\Silverstripe\SEO\Checks\Rules\OrphanPageCheck:
     *       lists_children_classes:
     *         - SilverStripe\Blog\Model\Blog
     *         - App\Pages\NewsHolder
     *
     * Other cases (a page linked from the template, a listing by tag) can be decided in an extension:
     * `updateIsOrphan(bool &$orphan, PageAnalysis $analysis)`.
     *
     * @config
     * @var string[]
     */
    private static array $lists_children_classes = [];

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
        $orphan = !$analysis->site()->isInMenu($page)
            && $analysis->site()->inboundLinkCount($page) === 0
            && !$this->listedByParent($page, $analysis);
        $this->extend('updateIsOrphan', $orphan, $analysis);
        if (!$orphan) {
            return $this->pass();
        }
        return $this->fail($this->_t(
            __CLASS__ . '.Message',
            'No other page links to this page, and it is not in the menu.'
        ));
    }

    /**
     * The parent is of a class that lists its children, and is reachable itself.
     */
    protected function listedByParent(SiteTree $page, PageAnalysis $analysis): bool
    {
        $parent = $page->ParentID ? $page->Parent() : null;
        if (!$parent || !$parent->exists()) {
            return false;
        }
        $listing = false;
        foreach ((array) static::config()->get('lists_children_classes') as $class) {
            if ($class && is_a($parent, (string) $class)) {
                $listing = true;
                break;
            }
        }
        return $listing && ($analysis->site()->isInMenu($parent) || $analysis->site()->inboundLinkCount($parent) > 0);
    }
}
