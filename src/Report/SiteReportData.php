<?php

namespace Restruct\Silverstripe\SEO\Report;

use Restruct\Silverstripe\PageRender\PageEligibility;
use Restruct\Silverstripe\SEO\Checks\Check;
use Restruct\Silverstripe\SEO\Checks\CheckResult;
use Restruct\Silverstripe\SEO\Checks\CheckRunner;
use Restruct\Silverstripe\SEO\Checks\PageAnalysis;
use Restruct\Silverstripe\SEO\Checks\SiteData;
use Restruct\Silverstripe\SEO\Model\SeoCheckResult;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Model\ArrayData;
use SilverStripe\Model\List\ArrayList;
use SilverStripe\Security\Member;
use SilverStripe\Versioned\Versioned;

/**
 * The rows and the summary of the site report, for one viewer, read from the stored draft results (nothing is
 * rendered here; {@see SiteCheck} and the page editor's panel fill the results).
 *
 * Listed: the draft pages {@see PageEligibility} allows for 'seo' that the viewer may view (the draft, or the
 * published version, with its published title, when the viewer may not view drafts). Which checks fail on a
 * page, and the link to edit it, only for pages the viewer may edit; a viewer who may only view a page sees its
 * counts. Counts never include an ignored check. No score: counts per severity, and in the summary the share of
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
            if ($severity === self::SEVERITY_NONE && ($row['Status'] === self::STATUS_UNCHECKED || $row['Problems'] > 0)) {
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
            if (!$row['Indexable']) {
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
     * @return array<int, array{row:array<string, mixed>, failing:string[], details:bool}>
     */
    protected function pages(): array
    {
        if ($this->pages !== null) {
            return $this->pages;
        }
        $this->pages = [];
        $results = [];
        foreach (SeoCheckResult::get()->filter('Stage', Versioned::DRAFT) as $result) {
            $results[(int) $result->PageID] = $result;
        }
        $titles = $this->checkTitles();
        $eligibility = PageEligibility::singleton();

        Versioned::withVersionedMode(function () use ($results, $titles, $eligibility) {
            Versioned::set_stage(Versioned::DRAFT);
            $site = SiteData::create();
            foreach (SiteTree::get()->sort('ID') as $page) {
                if (!$eligibility->isEligible($page, 'seo')) {
                    continue;
                }
                $title = $this->visibleTitle($page);
                if ($title === null) {
                    continue;
                }
                $details = (bool) $page->canEdit($this->viewer);
                $result = $results[(int) $page->ID] ?? null;
                $failing = $result ? $this->failingChecks($result) : [];
                $indexable = !PageAnalysis::create($page, null, $site)->isNoindex();
                $status = !$result ? self::STATUS_UNCHECKED : ($result->isStale() ? self::STATUS_STALE : self::STATUS_FRESH);
                $this->pages[(int) $page->ID] = [
                    'failing' => $failing,
                    'details' => $details,
                    'row' => [
                        'ID' => (int) $page->ID,
                        'Title' => $title,
                        'ClassName' => get_class($page),
                        'PageType' => (string) $page->i18n_singular_name(),
                        'Indexable' => $indexable,
                        'IndexableLabel' => $indexable ? _t(__CLASS__ . '.IndexableYes', 'Yes') : _t(__CLASS__ . '.IndexableNo', 'No (noindex)'),
                        'Problems' => $result ? (int) $result->Problems : 0,
                        'Warnings' => $result ? (int) $result->Warnings : 0,
                        'Tips' => $result ? (int) $result->Tips : 0,
                        'CheckedAt' => $result ? (string) $result->CheckedAt : '',
                        'Status' => $status,
                        'StatusLabel' => static::statusLabel($status),
                        'FailingChecks' => $details
                            ? implode(', ', array_map(fn ($id) => $titles[$id] ?? $id, $failing))
                            : '',
                        'EditLink' => $details ? (string) $page->CMSEditLink() : '',
                    ],
                ];
            }
        });
        return $this->pages;
    }

    /**
     * The title the viewer may see, or null when the viewer may not see the page: the draft's title when the
     * viewer may view the draft, else the published title (a viewer without draft rights still sees published
     * pages that have unpublished changes, but not their unpublished title).
     */
    protected function visibleTitle(SiteTree $draft): ?string
    {
        if ($draft->canView($this->viewer)) {
            return (string) $draft->Title;
        }
        if (!$draft->isPublished()) {
            return null;
        }
        $live = Versioned::get_by_stage(SiteTree::class, Versioned::LIVE)->byID((int) $draft->ID);
        return $live && $live->canView($this->viewer) ? (string) $live->Title : null;
    }

    /**
     * Ids of the failing, not ignored checks of a stored result.
     *
     * @return string[]
     */
    protected function failingChecks(SeoCheckResult $result): array
    {
        $ids = [];
        foreach ($result->getEntries() as $id => $entry) {
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
