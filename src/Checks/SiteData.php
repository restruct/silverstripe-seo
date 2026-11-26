<?php

namespace Restruct\Silverstripe\SEO\Checks;

use Restruct\Silverstripe\RenderedContentExtractor\PageEligibility;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Core\ClassInfo;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\ORM\DataList;
use SilverStripe\ORM\DB;
use SilverStripe\Versioned\Versioned;

/**
 * Site-level lookups the checks share: other indexable pages with the same title or description, inbound links
 * and menu placement. Read in the stage the checks run in (draft), so a page is compared with the site as the
 * editors currently have it.
 *
 * Computed titles and noindex verdicts of other pages are kept for the lifetime of the object, so one SiteData is
 * shared by the pages of a run: CheckRunner::run() / collect() / checkPage() and SiteCheck::checkPage() take one,
 * the site task creates one per run and the site job one per step.
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
        $columnMatch = [
            '"SiteTree"."MetaTitle" = ?' => $title,
            '("SiteTree"."MetaTitle" IS NULL OR "SiteTree"."MetaTitle" = \'\') AND "SiteTree"."Title" = ?' => $title,
        ];
        # As the database compares: case-insensitively
        $wanted = mb_strtolower($title);
        # A page class with a getMetaTitle() override decides its title in code, so its column says nothing:
        # its pages are found by their computed titles (computed once per SiteData), the others in SQL
        $getterClasses = $this->classesWithGetter('MetaTitle');
        $computed = $this->computedTitles();
        if ($getterClasses) {
            $conditions = [
                '"SiteTree"."ClassName" NOT IN (' . DB::placeholders($getterClasses) . ') AND ('
                    . implode(' OR ', array_keys($columnMatch)) . ')'
                    => array_merge($getterClasses, array_values($columnMatch)),
            ];
            $matched = array_keys(array_filter($computed, fn (string $computedTitle) => $computedTitle === $wanted));
            if ($matched) {
                $conditions['"SiteTree"."ID" IN (' . DB::placeholders($matched) . ')'] = $matched;
            }
            $candidates = $this->otherPages($page)->whereAny($conditions);
        } else {
            $candidates = $this->otherPages($page)->whereAny($columnMatch);
        }
        return $this->indexableAmong(
            $candidates,
            fn (SiteTree $other) => ($computed[(int) $other->ID] ?? mb_strtolower(trim((string) $other->SeoPageTitle()))) === $wanted
        );
    }

    /**
     * Other indexable pages with the same typed meta description. Derived descriptions are not compared: they
     * come from each page's own content.
     *
     * @return DataList<SiteTree>
     */
    public function pagesWithDescription(SiteTree $page, string $description): DataList
    {
        return $this->indexableAmong($this->otherPages($page)->filter('MetaDescription', trim($description)));
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
     * Pages other than this one, of a type the checks apply to.
     *
     * @return DataList<SiteTree>
     */
    protected function otherPages(SiteTree $page): DataList
    {
        $list = SiteTree::get()->exclude('ID', (int) $page->ID);
        # The excluded classes with their subclasses, as PageEligibility applies them
        $excluded = [];
        foreach (PageEligibility::singleton()->getExcludedClasses() as $class) {
            $excluded = array_merge($excluded, array_values(ClassInfo::subclassesFor($class)));
        }
        return $excluded ? $list->exclude('ClassName', array_unique($excluded)) : $list;
    }

    /**
     * The candidates that search engines may list (not noindex), and that pass $match, as a list of their own.
     *
     * The noindex column narrows the candidates in the query; pages of a class with a getMetaRobotsNoIndex()
     * override stay in, as their column says nothing. Every remaining candidate is then asked the way the head
     * decides it (getter, robots hook), so the column and the page never disagree here. The candidates are few
     * (pages with one title or one description), except for the pages of a class with a getMetaTitle()
     * override, which are all loaded: their titles exist only in PHP.
     *
     * @param DataList<SiteTree> $candidates
     * @param callable(SiteTree):bool|null $match
     * @return DataList<SiteTree>
     */
    protected function indexableAmong(DataList $candidates, ?callable $match = null): DataList
    {
        $getterClasses = $this->classesWithGetter('MetaRobotsNoIndex');
        if ($getterClasses) {
            $candidates = $candidates->whereAny([
                '"SiteTree"."MetaRobotsNoIndex" = 0' => [],
                '"SiteTree"."ClassName" IN (' . DB::placeholders($getterClasses) . ')' => $getterClasses,
            ]);
        } else {
            $candidates = $candidates->filter('MetaRobotsNoIndex', false);
        }
        $ids = [];
        foreach ($candidates as $other) {
            if (($match === null || $match($other)) && !$this->isNoindex($other)) {
                $ids[] = (int) $other->ID;
            }
        }
        # An empty IN () is no valid SQL; ID 0 matches nothing
        return SiteTree::get()->filter('ID', $ids ?: [0]);
    }

    /**
     * Whether another page asks not to be indexed, the way the head decides it; remembered per page for the
     * lifetime of this object.
     */
    protected function isNoindex(SiteTree $other): bool
    {
        return $this->noindex[(int) $other->ID] ??= PageAnalysis::create($other, null, $this)->isNoindex();
    }

    /**
     * The titles for search of the pages whose class has a getMetaTitle() override, lowercased, by page ID.
     * Computed on first use and kept for the lifetime of this object, so a check run, a job step or a task run
     * that shares one SiteData loads those pages once, however many pages it checks. Read in the stage of that
     * first use (the checks read the draft); a page edited later in the same run keeps its earlier title here.
     *
     * @return array<int, string>
     */
    protected function computedTitles(): array
    {
        return $this->computedTitles ??= $this->loadComputedTitles();
    }

    /**
     * @return array<int, string>
     */
    protected function loadComputedTitles(): array
    {
        $classes = $this->classesWithGetter('MetaTitle');
        if (!$classes) {
            return [];
        }
        $titles = [];
        foreach (SiteTree::get()->filter('ClassName', $classes) as $other) {
            $titles[(int) $other->ID] = mb_strtolower(trim((string) $other->SeoPageTitle()));
        }
        return $titles;
    }

    /**
     * @var array<int, string>|null
     */
    protected ?array $computedTitles = null;

    /**
     * @var array<int, bool>
     */
    protected array $noindex = [];

    /**
     * Page classes that read a field through a getter of their own (`getMetaTitle()`), from the class or an
     * extension. Cached per field for the lifetime of this object: a site run asks once per page.
     *
     * @return string[]
     */
    protected function classesWithGetter(string $field): array
    {
        if (!isset($this->getterClasses[$field])) {
            $classes = [];
            foreach (ClassInfo::subclassesFor(SiteTree::class) as $class) {
                if (SiteTree::singleton($class)->hasMethod('get' . $field)) {
                    $classes[] = $class;
                }
            }
            $this->getterClasses[$field] = $classes;
        }
        return $this->getterClasses[$field];
    }

    /**
     * @var array<string, string[]>
     */
    protected array $getterClasses = [];
}
