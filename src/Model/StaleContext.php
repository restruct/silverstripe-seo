<?php

namespace Restruct\Silverstripe\SEO\Model;

use Restruct\Silverstripe\SEO\Checks\CheckRunner;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\Queries\SQLSelect;
use SilverStripe\Versioned\Versioned;

/**
 * What a stored result's staleness is measured against, for one stage: the check configuration's fingerprint
 * ({@see CheckRunner::configHash()}), the last change of any page (for results that hold a check reading other
 * pages), and per page the last change of the page and of the blocks in its Elemental areas.
 *
 * Built once per report view or job step: one query on creation (the latest page change). {@see preload()}
 * fetches the per-page values for a set of pages in two more queries (one for the pages, one grouped query for
 * the blocks when Elemental is installed); without it they are fetched per page when asked, as a single
 * {@see SeoCheckResult::isStale()} call does.
 */
class StaleContext
{
    use Injectable;

    protected const ELEMENT = 'DNADesign\\Elemental\\Models\\BaseElement';

    protected string $configHash;

    protected string $siteEdited;

    /**
     * @var array<int, string|null> Page ID => LastEdited (null: no such page in the stage)
     */
    protected array $pageEdited = [];

    /**
     * @var array<int, string> Page ID => the latest LastEdited of its blocks ('' for none)
     */
    protected array $blocksEdited = [];

    public function __construct(protected string $stage = Versioned::DRAFT)
    {
        $this->configHash = CheckRunner::singleton()->configHash();
        $this->siteEdited = (string) SQLSelect::create('MAX("LastEdited")', $this->table(SiteTree::class))->execute()->value();
    }

    public function getStage(): string
    {
        return $this->stage;
    }

    public function configHash(): string
    {
        return $this->configHash;
    }

    /**
     * When any page of the stage last changed.
     */
    public function siteEdited(): string
    {
        return $this->siteEdited;
    }

    /**
     * Fetch the per-page values for these pages (objects of this stage, for their Elemental area IDs).
     *
     * @param iterable<SiteTree> $pages
     */
    public function preload(iterable $pages): static
    {
        $areasByPage = [];
        $ids = [];
        foreach ($pages as $page) {
            $id = (int) $page->ID;
            $ids[] = $id;
            $this->pageEdited[$id] = null;
            $areasByPage[$id] = $this->areaIDs($page);
        }
        if (!$ids) {
            return $this;
        }
        $rows = SQLSelect::create(['"ID"', '"LastEdited"'], $this->table(SiteTree::class))
            ->addWhere(['"ID" IN (' . implode(',', array_fill(0, count($ids), '?')) . ')' => $ids])
            ->execute();
        foreach ($rows as $row) {
            $this->pageEdited[(int) $row['ID']] = (string) $row['LastEdited'];
        }

        $areaEdited = $this->areaEdited(array_merge([], ...array_values($areasByPage)));
        foreach ($areasByPage as $id => $areas) {
            $latest = '';
            foreach ($areas as $area) {
                $latest = max($latest, $areaEdited[$area] ?? '');
            }
            $this->blocksEdited[$id] = $latest;
        }
        return $this;
    }

    /**
     * When the page last changed in this stage; null when it does not exist there.
     */
    public function pageEdited(int $pageID): ?string
    {
        if (!array_key_exists($pageID, $this->pageEdited)) {
            $page = Versioned::get_by_stage(SiteTree::class, $this->stage)->byID($pageID);
            if (!$page) {
                $this->pageEdited[$pageID] = null;
                return null;
            }
            $this->preload([$page]);
        }
        return $this->pageEdited[$pageID];
    }

    /**
     * When a block in one of the page's Elemental areas last changed; '' when it has none.
     */
    public function blocksEdited(int $pageID): string
    {
        if (!array_key_exists($pageID, $this->blocksEdited)) {
            $this->pageEdited($pageID);
        }
        return $this->blocksEdited[$pageID] ?? '';
    }

    /**
     * @return int[]
     */
    protected function areaIDs(SiteTree $page): array
    {
        if (!class_exists(static::ELEMENT) || !$page->hasMethod('getElementalRelations')) {
            return [];
        }
        return array_values(array_filter(array_map(
            fn ($relation) => (int) $page->getField($relation . 'ID'),
            (array) $page->getElementalRelations()
        )));
    }

    /**
     * Area ID => the latest LastEdited of its blocks, in one grouped query.
     *
     * @param int[] $areaIDs
     * @return array<int, string>
     */
    protected function areaEdited(array $areaIDs): array
    {
        $areaIDs = array_values(array_unique($areaIDs));
        if (!$areaIDs) {
            return [];
        }
        $rows = SQLSelect::create(['"ParentID"', 'Latest' => 'MAX("LastEdited")'], $this->table(static::ELEMENT))
            ->addWhere(['"ParentID" IN (' . implode(',', array_fill(0, count($areaIDs), '?')) . ')' => $areaIDs])
            ->addGroupBy('"ParentID"')
            ->execute();
        $latest = [];
        foreach ($rows as $row) {
            $latest[(int) $row['ParentID']] = (string) $row['Latest'];
        }
        return $latest;
    }

    /**
     * The quoted base table of a versioned class in this stage.
     */
    protected function table(string $class): string
    {
        $table = DataObject::getSchema()->baseDataTable($class);
        return '"' . ($this->stage === Versioned::LIVE ? $table . '_' . Versioned::LIVE : $table) . '"';
    }
}
