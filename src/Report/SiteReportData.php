<?php

namespace Restruct\Silverstripe\SEO\Report;

use Restruct\Silverstripe\PageRender\PageEligibility;
use Restruct\Silverstripe\SEO\Checks\Check;
use Restruct\Silverstripe\SEO\Checks\CheckResult;
use Restruct\Silverstripe\SEO\Checks\CheckRunner;
use Restruct\Silverstripe\SEO\Checks\PageAnalysis;
use Restruct\Silverstripe\SEO\Checks\SiteData;
use Restruct\Silverstripe\SEO\Model\SeoCheckResult;
use Restruct\Silverstripe\SEO\Model\StaleContext;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Model\ArrayData;
use SilverStripe\Model\List\ArrayList;
use SilverStripe\ORM\Queries\SQLSelect;
use SilverStripe\Security\InheritedPermissions;
use SilverStripe\Security\Member;
use SilverStripe\Versioned\Versioned;

/**
 * The rows and the summary of the site report, for one viewer, read from the stored draft results (nothing is
 * rendered here; {@see SiteCheck} and the page editor's panel fill the results).
 *
 * Listed: the draft pages {@see PageEligibility} allows for 'seo' that the viewer may view (the draft, or the
 * published version, with its published title and nothing taken from the draft, when the viewer may not view
 * drafts). Which checks fail on a page, and the link to edit it, only for pages the viewer may edit; a viewer
 * who may only view a page sees its counts. Counts never include an ignored check. No score: counts per severity, and in the summary the share of
 * indexable pages without problems.
 */
class SiteReportData
{
    use Configurable;
    use Injectable;

    public const STATUS_FRESH = 'fresh';

    public const STATUS_STALE = 'stale';

    public const STATUS_UNCHECKED = 'unchecked';

    /**
     * Severity filter value: checked pages without problems.
     */
    public const SEVERITY_NONE = 'none';

    /**
     * How many of the most failing checks the summary lists.
     */
    private static int $top_checks = 5;

    /**
     * @var array<int, array>|null Per visible page: the row, its failing check ids and whether details show
     */
    protected ?array $pages = null;

    public function __construct(protected ?Member $viewer)
    {
    }

    /**
     * The rows, filtered. Filters (each optional, an unknown value is ignored): `Severity` (problem, warning,
     * tip: at least one of those; none: checked without problems), `Check` (a check id failing on the page,
     * only matched on pages whose details the viewer sees), `PageType` (a page class, subclasses not
     * included), `Status` (fresh, stale, unchecked).
     *
     * @param array<string, mixed> $filters
     * @return ArrayList<ArrayData>
     */
    public function rows(array $filters = []): ArrayList
    {
        $severity = (string) ($filters['Severity'] ?? '');
        $check = (string) ($filters['Check'] ?? '');
        $type = (string) ($filters['PageType'] ?? '');
        $status = (string) ($filters['Status'] ?? '');
        $severityField = [Check::PROBLEM => 'Problems', Check::WARNING => 'Warnings', Check::TIP => 'Tips'][$severity] ?? null;

        $rows = ArrayList::create();
        foreach ($this->pages() as $page) {
            $row = $page['row'];
            if ($severityField && (int) $row[$severityField] < 1) {
                continue;
            }
            if ($severity === self::SEVERITY_NONE && ($row['DraftHidden'] || $row['Status'] === self::STATUS_UNCHECKED || $row['Problems'] > 0)) {
                continue;
            }
            if ($check !== '' && (!$page['details'] || !in_array($check, $page['failing'], true))) {
                continue;
            }
            if ($type !== '' && $row['ClassName'] !== $type) {
                continue;
            }
            if (in_array($status, [self::STATUS_FRESH, self::STATUS_STALE, self::STATUS_UNCHECKED], true) && $row['Status'] !== $status) {
                continue;
            }
            $rows->push(ArrayData::create($row));
        }
        return $rows;
    }

    /**
     * The site status, over the pages the viewer may view, unfiltered: indexable pages (eligible, not noindex),
     * how many of them were checked without problems, how many are unchecked or out of date, and the checks
     * failing on most indexable pages the viewer may edit.
     *
     * @return array{indexable:int, withoutProblems:int, unchecked:int, stale:int,
     *               topChecks:array<int, array{id:string, title:string, count:int}>}
     */
    public function summary(): array
    {
        $summary = ['indexable' => 0, 'withoutProblems' => 0, 'unchecked' => 0, 'stale' => 0, 'topChecks' => []];
        $failing = [];
        foreach ($this->pages() as $page) {
            $row = $page['row'];
            # A row shown through the published version has no draft-derived values to count
            if ($row['DraftHidden'] || !$row['Indexable']) {
                continue;
            }
            $summary['indexable']++;
            if ($row['Status'] === self::STATUS_UNCHECKED) {
                $summary['unchecked']++;
                continue;
            }
            if ($row['Status'] === self::STATUS_STALE) {
                $summary['stale']++;
            }
            if ($row['Problems'] === 0) {
                $summary['withoutProblems']++;
            }
            # Which checks fail is a detail: only pages the viewer may edit count towards the top failing checks
            if (!$page['details']) {
                continue;
            }
            foreach ($page['failing'] as $id) {
                $failing[$id] = ($failing[$id] ?? 0) + 1;
            }
        }
        $titles = $this->checkTitles();
        $top = [];
        foreach ($failing as $id => $count) {
            $top[] = ['id' => (string) $id, 'title' => $titles[$id] ?? (string) $id, 'count' => $count];
        }
        usort($top, fn ($a, $b) => [$b['count'], $a['title']] <=> [$a['count'], $b['title']]);
        $summary['topChecks'] = array_slice($top, 0, max(0, (int) static::config()->get('top_checks')));
        return $summary;
    }

    /**
     * Page classes among the listed pages, class => singular name, for the page type filter.
     *
     * @return array<string, string>
     */
    public function pageTypes(): array
    {
        $types = [];
        foreach ($this->pages() as $page) {
            $types[$page['row']['ClassName']] = $page['row']['PageType'];
        }
        asort($types);
        return $types;
    }

    /**
     * Ids and titles of the registered checks, for the check filter.
     *
     * @return array<string, string>
     */
    public function checkTitles(): array
    {
        $titles = [CheckRunner::RENDER_ENTRY => _t(CheckRunner::class . '.RenderTitle', 'The page renders')];
        foreach (CheckRunner::singleton()->getChecks() as $id => $check) {
            $titles[$id] = $check->title();
        }
        return $titles;
    }

    /**
     * Per listed page: the row, its failing check ids (only read for pages with details) and whether details
     * show. Built once per instance, with a fixed number of queries for the results and their staleness, plus
     * what the permission checks cost (batched through the permission and version caches).
     *
     * @return array<int, array{row:array<string, mixed>, failing:string[], details:bool}>
     */
    protected function pages(): array
    {
        if ($this->pages !== null) {
            return $this->pages;
        }
        $this->pages = [];
        $titles = $this->checkTitles();
        $eligibility = PageEligibility::singleton();

        Versioned::withVersionedMode(function () use ($titles, $eligibility) {
            Versioned::set_stage(Versioned::DRAFT);
            $pages = [];
            foreach (SiteTree::get()->sort('ID') as $page) {
                if ($eligibility->isEligible($page, 'seo')) {
                    $pages[(int) $page->ID] = $page;
                }
            }
            if (!$pages) {
                return;
            }
            $ids = array_keys($pages);
            $this->warmPermissionCaches($ids);
            $results = $this->resultRows();
            $context = StaleContext::create(Versioned::DRAFT)->preload($pages);
            $site = SiteData::create();

            $visible = [];
            foreach ($pages as $id => $page) {
                $draftVisible = (bool) $page->canView($this->viewer);
                $title = $draftVisible ? (string) $page->Title : $this->liveTitle($page);
                if ($title !== null) {
                    $visible[$id] = [$title, $draftVisible, $draftVisible && $page->canEdit($this->viewer)];
                }
            }
            # The results JSON only for pages whose failing checks are shown
            $withDetails = array_keys(array_filter(
                $visible,
                fn ($v, $id) => $v[2] && isset($results[$id]),
                ARRAY_FILTER_USE_BOTH
            ));
            $failing = $this->failingByPage($withDetails);

            foreach ($visible as $id => [$title, $draftVisible, $details]) {
                $page = $pages[$id];
                $this->pages[$id] = [
                    'failing' => $details ? ($failing[$id] ?? []) : [],
                    'details' => $details,
                    'row' => $draftVisible
                        ? $this->draftRow($page, $title, $results[$id] ?? null, $context, $site, $details ? ($failing[$id] ?? []) : null, $titles)
                        : $this->liveOnlyRow($page, $title),
                ];
            }
        });
        return $this->pages;
    }

    /**
     * The row of a page whose draft the viewer may see: everything from the draft and its stored result.
     *
     * @param array<string, mixed>|null $result The raw result columns
     * @param string[]|null $failing Failing check ids, null when the viewer gets no details
     * @param array<string, string> $titles
     * @return array<string, mixed>
     */
    protected function draftRow(SiteTree $page, string $title, ?array $result, StaleContext $context, SiteData $site, ?array $failing, array $titles): array
    {
        $indexable = !PageAnalysis::create($page, null, $site)->isNoindex();
        $status = !$result
            ? self::STATUS_UNCHECKED
            : (SeoCheckResult::isStaleRow($result, $context) ? self::STATUS_STALE : self::STATUS_FRESH);
        return [
            'ID' => (int) $page->ID,
            'Title' => $title,
            'ClassName' => get_class($page),
            'PageType' => (string) $page->i18n_singular_name(),
            'DraftHidden' => false,
            'Indexable' => $indexable,
            'IndexableLabel' => $indexable ? _t(__CLASS__ . '.IndexableYes', 'Yes') : _t(__CLASS__ . '.IndexableNo', 'No (noindex)'),
            'Problems' => $result ? (int) $result['Problems'] : 0,
            'Warnings' => $result ? (int) $result['Warnings'] : 0,
            'Tips' => $result ? (int) $result['Tips'] : 0,
            'CheckedAt' => $result ? (string) $result['CheckedAt'] : '',
            'Status' => $status,
            'StatusLabel' => static::statusLabel($status),
            'FailingChecks' => $failing !== null
                ? implode(', ', array_map(fn ($id) => $titles[$id] ?? $id, $failing))
                : '',
            'EditLink' => $failing !== null ? (string) $page->CMSEditLink() : '',
        ];
    }

    /**
     * The row of a page the viewer sees only as published: the published title, and nothing that comes from
     * the draft or its check (counts, indexing, page type, status), which the viewer may not see.
     *
     * @return array<string, mixed>
     */
    protected function liveOnlyRow(SiteTree $page, string $title): array
    {
        $na = _t(__CLASS__ . '.NeedsDraftAccess', 'Not available without draft access');
        return [
            'ID' => (int) $page->ID,
            'Title' => $title,
            'ClassName' => get_class($page),
            'PageType' => $na,
            'DraftHidden' => true,
            'Indexable' => null,
            'IndexableLabel' => $na,
            'Problems' => null,
            'Warnings' => null,
            'Tips' => null,
            'CheckedAt' => '',
            'Status' => '',
            'StatusLabel' => $na,
            'FailingChecks' => '',
            'EditLink' => '',
        ];
    }

    /**
     * Fill core's permission and version caches for all pages at once, so the canView() and canEdit() calls per
     * page that follow are answered from them (the per-page calls stay, for extensions that change access).
     *
     * @param int[] $ids
     */
    protected function warmPermissionCaches(array $ids): void
    {
        Versioned::prepopulate_versionnumber_cache(SiteTree::class, Versioned::LIVE, $ids);
        Versioned::prepopulate_versionnumber_cache(SiteTree::class, Versioned::DRAFT, $ids);
        $checker = SiteTree::singleton()->getPermissionChecker();
        if ($checker instanceof InheritedPermissions) {
            $checker->canViewMultiple($ids, $this->viewer, false);
            $checker->canEditMultiple($ids, $this->viewer, false);
        }
    }

    /**
     * The draft results without their JSON, page ID => columns.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function resultRows(): array
    {
        $columns = ['"PageID"', '"Stage"', '"CheckedAt"', '"Problems"', '"Warnings"', '"Tips"', '"Stale"', '"Format"', '"ConfigHash"', '"CrossPage"'];
        $rows = [];
        foreach (SQLSelect::create($columns, '"SeoCheckResult"', ['"Stage"' => Versioned::DRAFT])->execute() as $row) {
            $rows[(int) $row['PageID']] = $row;
        }
        return $rows;
    }

    /**
     * Failing, not ignored check ids per page, decoded from the stored JSON of these pages only.
     *
     * @param int[] $pageIDs
     * @return array<int, string[]>
     */
    protected function failingByPage(array $pageIDs): array
    {
        if (!$pageIDs) {
            return [];
        }
        $failing = [];
        $query = SQLSelect::create(['"PageID"', '"Results"'], '"SeoCheckResult"')
            ->addWhere(['"Stage"' => Versioned::DRAFT])
            ->addWhere(['"PageID" IN (' . implode(',', array_fill(0, count($pageIDs), '?')) . ')' => $pageIDs]);
        foreach ($query->execute() as $row) {
            $entries = json_decode((string) $row['Results'], true);
            $failing[(int) $row['PageID']] = $this->failingChecks(is_array($entries) ? $entries : []);
        }
        return $failing;
    }

    /**
     * The published title, when the viewer may view the published version; null otherwise.
     */
    protected function liveTitle(SiteTree $draft): ?string
    {
        if (!$draft->isPublished()) {
            return null;
        }
        $live = Versioned::get_by_stage(SiteTree::class, Versioned::LIVE)->byID((int) $draft->ID);
        return $live && $live->canView($this->viewer) ? (string) $live->Title : null;
    }

    /**
     * Ids of the failing, not ignored checks among stored entries.
     *
     * @param array<string, array> $entries
     * @return string[]
     */
    protected function failingChecks(array $entries): array
    {
        $ids = [];
        foreach ($entries as $id => $entry) {
            if (($entry['status'] ?? '') === CheckResult::FAIL && empty($entry['ignored'])) {
                $ids[] = (string) $id;
            }
        }
        return $ids;
    }

    public static function statusLabel(string $status): string
    {
        return match ($status) {
            self::STATUS_FRESH => _t(__CLASS__ . '.StatusFresh', 'Up to date'),
            self::STATUS_STALE => _t(__CLASS__ . '.StatusStale', 'Out of date'),
            default => _t(__CLASS__ . '.StatusUnchecked', 'Not checked'),
        };
    }
}
