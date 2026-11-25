<?php

namespace Restruct\Silverstripe\SEO\Checks;

use Restruct\Silverstripe\PageRender\PageEligibility;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Core\ClassInfo;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\ORM\DataList;
use SilverStripe\Versioned\Versioned;

/**
 * Site-level lookups the checks share: other indexable pages with the same title or description, inbound links
 * and menu placement. Read in the stage the checks run in (draft), so a page is compared with the site as the
 * editors currently have it.
 */
class SiteData
{
    use Injectable;

    /**
     * Other indexable pages whose title for search (meta title, else page title) is the same, case-insensitively
     * as the database compares.
     *
     * @return DataList<SiteTree>
     */
    public function pagesWithTitle(SiteTree $page, string $title): DataList
    {
        $title = trim($title);
        return $this->otherIndexablePages($page)->whereAny([
            '"SiteTree"."MetaTitle" = ?' => $title,
            '("SiteTree"."MetaTitle" IS NULL OR "SiteTree"."MetaTitle" = \'\') AND "SiteTree"."Title" = ?' => $title,
        ]);
    }

    /**
     * Other indexable pages with the same typed meta description. Derived descriptions are not compared: they
     * come from each page's own content.
     *
     * @return DataList<SiteTree>
     */
    public function pagesWithDescription(SiteTree $page, string $description): DataList
    {
        return $this->otherIndexablePages($page)->filter('MetaDescription', trim($description));
    }

    public function inboundLinkCount(SiteTree $page): int
    {
        return LinkGraph::singleton()->inboundCount((int) $page->ID);
    }

    /**
     * Whether the page can be reached through the site's menus: it is shown in menus, and so is every page above it.
     */
    public function isInMenu(SiteTree $page): bool
    {
        $current = $page;
        $seen = [];
        while ($current instanceof SiteTree && $current->ID && !isset($seen[$current->ID])) {
            $seen[$current->ID] = true;
            if (!$current->ShowInMenus) {
                return false;
            }
            if (!$current->ParentID) {
                return true;
            }
            $current = Versioned::get_by_stage(SiteTree::class, Versioned::get_stage() ?: Versioned::DRAFT)
                ->byID($current->ParentID);
        }
        # A parent that does not exist (any more): the page cannot be reached through it
        return false;
    }

    /**
     * Pages other than this one that search engines may list: not noindex, and of a type the checks apply to.
     *
     * @return DataList<SiteTree>
     */
    protected function otherIndexablePages(SiteTree $page): DataList
    {
        $list = SiteTree::get()->exclude('ID', (int) $page->ID)->filter('MetaRobotsNoIndex', false);
        # The excluded classes with their subclasses, as PageEligibility applies them
        $excluded = [];
        foreach (PageEligibility::singleton()->getExcludedClasses() as $class) {
            $excluded = array_merge($excluded, array_values(ClassInfo::subclassesFor($class)));
        }
        return $excluded ? $list->exclude('ClassName', array_unique($excluded)) : $list;
    }
}
