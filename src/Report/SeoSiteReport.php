<?php

namespace Restruct\Silverstripe\SEO\Report;

use Restruct\Silverstripe\SEO\Checks\Check;
use SilverStripe\Core\Convert;
use SilverStripe\Forms\DropdownField;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\GridField\GridFieldExportButton;
use SilverStripe\Forms\LiteralField;
use SilverStripe\Reports\Report;
use SilverStripe\Security\Permission;
use SilverStripe\Security\PermissionProvider;
use SilverStripe\Security\Security;

/**
 * "SEO status" in the CMS Reports section: every eligible page with its number of problems, warnings and tips,
 * when it was last checked and whether that result is out of date, with filters, sorting, a CSV export and a
 * summary of the site at the top ("pages without problems: N of M"). No score.
 *
 * A report rather than a section of its own: silverstripe/reports comes with silverstripe/cms, and a Report
 * brings the filter form, the sortable and paginated list, print and export, which a LeftAndMain would rebuild.
 * The cost is that a reader needs access to the Reports section (CMS_ACCESS_ReportAdmin) on top of the
 * VIEW_SEO_REPORT permission this report asks for (administrators have both).
 *
 * The report only reads stored results ({@see SiteReportData}); they come from the page editor's panel and from
 * the site check ({@see SiteCheck}: the queued job or `sake tasks:seo-check-site`).
 */
class SeoSiteReport extends Report implements PermissionProvider
{
    public const PERMISSION = 'VIEW_SEO_REPORT';

    /**
     * How the stored results are refreshed for the whole site, shown in the report; set to the job class when
     * queuedjobs is installed (_config/queuedjobs.yml).
     */
    private static ?string $site_check_job = null;

    protected $sort = 10;

    public function title()
    {
        return _t(__CLASS__ . '.Title', 'SEO status');
    }

    public function description()
    {
        $how = static::config()->get('site_check_job')
            ? _t(__CLASS__ . '.RefreshJob', 'The results of the whole site are refreshed by the queued job "SEO checks of the whole site".')
            : _t(__CLASS__ . '.RefreshTask', 'The results of the whole site are refreshed by running sake tasks:seo-check-site.');
        return Convert::raw2xml(_t(
            __CLASS__ . '.Description',
            'Problems, warnings and tips per page, from the stored SEO checks. A page is checked again when an editor opens it after a change.'
        ) . ' ' . $how);
    }

    public function canView($member = null)
    {
        if (!$member && $member !== false) {
            $member = Security::getCurrentUser();
        }
        if (!$member || !Permission::checkMember($member, static::PERMISSION)) {
            return false;
        }
        return parent::canView($member);
    }

    public function providePermissions()
    {
        return [
            static::PERMISSION => [
                'name' => _t(__CLASS__ . '.PermissionName', 'View the SEO status report'),
                'help' => _t(__CLASS__ . '.PermissionHelp', 'See the SEO check results of every page the member may view, in Reports. Which checks fail is shown only for pages the member may edit.'),
                'category' => _t(__CLASS__ . '.PermissionCategory', 'SEO'),
            ],
        ];
    }

    protected function data(): SiteReportData
    {
        return SiteReportData::create(Security::getCurrentUser());
    }

    /**
     * @param array<string, mixed> $params The filters ({@see SiteReportData::rows()})
     */
    public function sourceRecords($params = [], $sort = null, $limit = null)
    {
        $rows = $this->data()->rows((array) $params);
        $rows = $rows->sort(static::sortMap($sort ?: 'Problems DESC, Warnings DESC, Tips DESC, Title ASC'));
        return $limit ? $rows->limit($limit) : $rows;
    }

    /**
     * "A DESC, B ASC" as [A => DESC, B => ASC]: ArrayList::sort() reads one column from a string.
     *
     * @param string|array $sort
     * @return array<string, string>
     */
    protected static function sortMap(string|array $sort): array
    {
        if (is_array($sort)) {
            return $sort;
        }
        $map = [];
        foreach (array_filter(array_map('trim', explode(',', $sort))) as $part) {
            $bits = preg_split('/\s+/', $part);
            $map[$bits[0]] = strtoupper($bits[1] ?? 'ASC') === 'DESC' ? 'DESC' : 'ASC';
        }
        return $map;
    }

    public function columns()
    {
        return [
            'Title' => [
                'title' => _t(__CLASS__ . '.ColumnTitle', 'Page'),
                'formatting' => function ($value, $item) {
                    $title = Convert::raw2xml((string) $item->Title);
                    return $item->EditLink
                        ? sprintf('<a class="grid-field__link-block" href="%s">%s</a>', Convert::raw2att($item->EditLink), $title)
                        : $title;
                },
            ],
            'PageType' => _t(__CLASS__ . '.ColumnPageType', 'Page type'),
            'Problems' => _t(__CLASS__ . '.ColumnProblems', 'Problems'),
            'Warnings' => _t(__CLASS__ . '.ColumnWarnings', 'Warnings'),
            'Tips' => _t(__CLASS__ . '.ColumnTips', 'Tips'),
            'CheckedAt' => [
                'title' => _t(__CLASS__ . '.ColumnCheckedAt', 'Last checked'),
                'casting' => 'Datetime->Nice',
            ],
            'StatusLabel' => _t(__CLASS__ . '.ColumnStatus', 'Status'),
            'IndexableLabel' => _t(__CLASS__ . '.ColumnIndexable', 'Indexable'),
            'FailingChecks' => _t(__CLASS__ . '.ColumnFailingChecks', 'Failing checks'),
        ];
    }

    /**
     * Row field => header of the CSV export.
     *
     * @return array<string, string>
     */
    public static function csvColumns(): array
    {
        return [
            'Title' => _t(__CLASS__ . '.ColumnTitle', 'Page'),
            'PageType' => _t(__CLASS__ . '.ColumnPageType', 'Page type'),
            'Problems' => _t(__CLASS__ . '.ColumnProblems', 'Problems'),
            'Warnings' => _t(__CLASS__ . '.ColumnWarnings', 'Warnings'),
            'Tips' => _t(__CLASS__ . '.ColumnTips', 'Tips'),
            'CheckedAt' => _t(__CLASS__ . '.ColumnCheckedAt', 'Last checked'),
            'StatusLabel' => _t(__CLASS__ . '.ColumnStatus', 'Status'),
            'IndexableLabel' => _t(__CLASS__ . '.ColumnIndexable', 'Indexable'),
            'FailingChecks' => _t(__CLASS__ . '.ColumnFailingChecks', 'Failing checks'),
            'EditLink' => _t(__CLASS__ . '.ColumnEditLink', 'Edit link'),
        ];
    }

    public function parameterFields()
    {
        $data = $this->data();
        return FieldList::create(
            DropdownField::create('Severity', _t(__CLASS__ . '.FilterSeverity', 'Severity'), [
                Check::PROBLEM => _t(__CLASS__ . '.SeverityProblem', 'With problems'),
                Check::WARNING => _t(__CLASS__ . '.SeverityWarning', 'With warnings'),
                Check::TIP => _t(__CLASS__ . '.SeverityTip', 'With tips'),
                SiteReportData::SEVERITY_NONE => _t(__CLASS__ . '.SeverityNone', 'Checked, without problems'),
            ])->setEmptyString(_t(__CLASS__ . '.Any', 'Any')),
            DropdownField::create('Check', _t(__CLASS__ . '.FilterCheck', 'Failing check'), $data->checkTitles())
                ->setEmptyString(_t(__CLASS__ . '.Any', 'Any')),
            DropdownField::create('PageType', _t(__CLASS__ . '.FilterPageType', 'Page type'), $data->pageTypes())
                ->setEmptyString(_t(__CLASS__ . '.Any', 'Any')),
            DropdownField::create('Status', _t(__CLASS__ . '.FilterStatus', 'Status'), [
                SiteReportData::STATUS_STALE => SiteReportData::statusLabel(SiteReportData::STATUS_STALE),
                SiteReportData::STATUS_UNCHECKED => SiteReportData::statusLabel(SiteReportData::STATUS_UNCHECKED),
                SiteReportData::STATUS_FRESH => SiteReportData::statusLabel(SiteReportData::STATUS_FRESH),
            ])->setEmptyString(_t(__CLASS__ . '.Any', 'Any'))
        );
    }

    public function getReportField()
    {
        $grid = parent::getReportField();
        $config = $grid->getConfig();
        # The core button prefixes a tab to formula-like cells and exports the on-screen columns; ours quotes
        # them and adds the edit link
        $config->removeComponentsByType(GridFieldExportButton::class);
        $config->addComponent(SeoReportExportButton::create('buttons-before-left'));
        return $grid;
    }

    public function getCMSFields()
    {
        $fields = parent::getCMSFields();
        $first = $fields->first();
        $fields->insertBefore($first ? $first->getName() : 'Report', LiteralField::create('SeoReportSummary', $this->summaryHtml()));
        return $fields;
    }

    /**
     * The summary above the list, escaped: indexable pages without problems, unchecked and out-of-date results,
     * the checks failing on most pages.
     */
    protected function summaryHtml(): string
    {
        $summary = $this->data()->summary();
        $lines = [
            _t(__CLASS__ . '.SummaryWithoutProblems', 'Pages without problems: {count} of {total} indexable pages', [
                'count' => $summary['withoutProblems'],
                'total' => $summary['indexable'],
            ]),
        ];
        if ($summary['unchecked']) {
            $lines[] = _t(__CLASS__ . '.SummaryUnchecked', 'Not checked yet: {count}', ['count' => $summary['unchecked']]);
        }
        if ($summary['stale']) {
            $lines[] = _t(__CLASS__ . '.SummaryStale', 'Out of date: {count}', ['count' => $summary['stale']]);
        }
        $html = '<div class="seo-report-summary mb-4"><p><strong>' . Convert::raw2xml(array_shift($lines)) . '</strong></p>';
        if ($lines) {
            $html .= '<p>' . Convert::raw2xml(implode(' / ', $lines)) . '</p>';
        }
        if ($summary['topChecks']) {
            $html .= '<p>' . Convert::raw2xml(_t(__CLASS__ . '.SummaryTopChecks', 'Failing on most pages:')) . '</p><ul>';
            foreach ($summary['topChecks'] as $check) {
                $html .= '<li>' . Convert::raw2xml(_t(__CLASS__ . '.SummaryTopCheck', '{title}: {count} pages', [
                    'title' => $check['title'],
                    'count' => $check['count'],
                ])) . '</li>';
            }
            $html .= '</ul>';
        }
        return $html . '</div>';
    }
}
