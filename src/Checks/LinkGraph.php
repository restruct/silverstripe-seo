<?php

namespace Restruct\Silverstripe\SEO\Checks;

use Psr\SimpleCache\CacheInterface;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\CMS\Model\SiteTreeLink;
use SilverStripe\Core\ClassInfo;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\Queries\SQLSelect;

/**
 * How many other records link to each page, site-wide, from core's link tracking: every DataObject with an HTML
 * field records the pages its `[sitetree_link]` shortcodes point at (SiteTreeLink), so links in Elemental blocks
 * count as well as links in page content. A link from a page to itself is not counted.
 *
 * Built with one grouped query and cached (`Psr\SimpleCache\CacheInterface.SeoLinkGraph`). The cache is cleared
 * when a page is written, published, unpublished or archived; it also expires after `cache_lifetime` seconds,
 * which covers blocks saved without their page.
 *
 * Link tracking records the draft content (it is skipped for writes to the live stage), so the graph is that of
 * the draft site. Links in templates (a footer, a hard-coded menu) are not in it; menus are taken into account
 * separately by {@see SiteData::isInMenu()}.
 */
class LinkGraph
{
    use Configurable;
    use Injectable;

    private const CACHE_KEY = 'inbound';

    /**
     * Seconds the graph is kept at most.
     */
    private static int $cache_lifetime = 3600;

    /**
     * @var array<int, int>|null
     */
    protected ?array $counts = null;

    public function inboundCount(int $pageID): int
    {
        return $this->counts()[$pageID] ?? 0;
    }

    /**
     * Forget the graph, here and in the cache.
     */
    public function clear(): void
    {
        $this->counts = null;
        $this->cache()->delete(self::CACHE_KEY);
    }

    /**
     * @return array<int, int> Inbound link count by page ID
     */
    protected function counts(): array
    {
        if ($this->counts !== null) {
            return $this->counts;
        }
        $cached = $this->cache()->get(self::CACHE_KEY);
        if (is_array($cached)) {
            return $this->counts = $cached;
        }
        $this->counts = $this->build();
        $this->cache()->set(self::CACHE_KEY, $this->counts, (int) static::config()->get('cache_lifetime'));
        return $this->counts;
    }

    /**
     * @return array<int, int>
     */
    protected function build(): array
    {
        $table = DataObject::getSchema()->tableName(SiteTreeLink::class);
        $pageClasses = ClassInfo::subclassesFor(SiteTree::class, false);
        $select = SQLSelect::create()
            ->setFrom("\"$table\"")
            ->setSelect([
                'LinkedID' => '"LinkedID"',
                # Distinct linking records: two links from one page count once
                'Linkers' => 'COUNT(DISTINCT CONCAT("ParentClass", \'#\', "ParentID"))',
            ])
            ->addWhere('"LinkedID" > 0')
            ->setGroupBy('"LinkedID"');
        if ($pageClasses) {
            # Not the page linking to itself
            $placeholders = implode(', ', array_fill(0, count($pageClasses), '?'));
            $select->addWhere([
                "NOT (\"ParentID\" = \"LinkedID\" AND \"ParentClass\" IN ($placeholders))" => array_values($pageClasses),
            ]);
        }

        $counts = [];
        foreach ($select->execute() as $row) {
            $counts[(int) $row['LinkedID']] = (int) $row['Linkers'];
        }
        return $counts;
    }

    protected function cache(): CacheInterface
    {
        return Injector::inst()->get(CacheInterface::class . '.SeoLinkGraph');
    }
}
