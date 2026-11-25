<?php

namespace Restruct\Silverstripe\SEO\Tests\Report;

use Restruct\Silverstripe\SEO\Checks\Check;
use Restruct\Silverstripe\SEO\Checks\CheckResult;
use Restruct\Silverstripe\SEO\Model\SeoCheckIgnore;
use Restruct\Silverstripe\SEO\Model\SeoCheckResult;
use Restruct\Silverstripe\SEO\Report\SeoReportCsv;
use Restruct\Silverstripe\SEO\Report\SeoReportExportButton;
use Restruct\Silverstripe\SEO\Report\SeoSiteReport;
use Restruct\Silverstripe\SEO\Report\SiteReportData;
use SilverStripe\CMS\Model\RedirectorPage;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Forms\GridField\GridField;
use SilverStripe\Forms\GridField\GridFieldExportButton;
use SilverStripe\ORM\FieldType\DBDatetime;
use SilverStripe\Reports\Report;
use SilverStripe\Security\Group;
use SilverStripe\Security\Member;
use SilverStripe\Security\Security;
use SilverStripe\Versioned\Versioned;

/**
 * The site report: who may see it, which pages it lists, the filters, the summary, and the CSV export.
 */
class SeoSiteReportTest extends SapphireTest
{
    protected $usesDatabase = true;

    /**
     * The codes a report reader holds: the Reports section and the SEO report.
     */
    protected const READER = ['CMS_ACCESS_ReportAdmin', 'VIEW_SEO_REPORT'];

    protected function setUp(): void
    {
        parent::setUp();
        DBDatetime::set_mock_now('2026-11-25 10:00:00');
    }

    protected function tearDown(): void
    {
        DBDatetime::clear_mock_now();
        parent::tearDown();
    }

    protected function publishedPage(string $title, array $data = []): SiteTree
    {
        $page = SiteTree::create(array_merge(['Title' => $title], $data));
        $page->write();
        $page->publishSingle();
        return $page;
    }

    /**
     * Store a result in which the given checks fail; checked an hour after the page was made, so it is fresh.
     *
     * @param array<string, string> $failing check id => severity
     */
    protected function storeResult(SiteTree $page, array $failing): SeoCheckResult
    {
        $entries = [];
        foreach ($failing as $id => $severity) {
            $entries[$id] = [
                'id' => $id,
                'group' => Check::GROUP_CONTENT,
                'severity' => $severity,
                'priority' => Check::HIGH,
                'title' => $id,
                'status' => CheckResult::FAIL,
                'message' => 'Wrong.',
                'details' => [],
                'howToFix' => '',
                'docs' => '',
                'ignored' => false,
                'ignoredBy' => null,
            ];
        }
        $entries['canonical'] = ['id' => 'canonical', 'severity' => Check::PROBLEM, 'status' => CheckResult::PASS, 'title' => 'canonical'];
        return SeoCheckResult::store($page, Versioned::DRAFT, SeoCheckResult::applyIgnores($page, $entries), false, '2026-11-25 11:00:00');
    }

    /**
     * Three checked pages, one unchecked, one noindex.
     *
     * @return array<string, SiteTree>
     */
    protected function site(): array
    {
        $pages = [
            'broken' => $this->publishedPage('Broken page'),
            'warned' => $this->publishedPage('Warned page'),
            'clean' => $this->publishedPage('Clean page'),
            'unchecked' => $this->publishedPage('Unchecked page'),
            'hidden' => $this->publishedPage('Hidden page', ['MetaRobotsNoIndex' => true]),
        ];
        $this->storeResult($pages['broken'], ['title-missing' => Check::PROBLEM, 'h1-missing' => Check::WARNING, 'og-image' => Check::TIP]);
        $this->storeResult($pages['warned'], ['h1-missing' => Check::WARNING, 'og-image' => Check::TIP]);
        $this->storeResult($pages['clean'], ['og-image' => Check::TIP]);
        $this->storeResult($pages['hidden'], ['noindex-published' => Check::WARNING]);
        return $pages;
    }

    /**
     * @return array<string, array<string, mixed>> Rows keyed by page title
     */
    protected function rows(array $filters = [], ?Member $viewer = null): array
    {
        $rows = [];
        foreach (SiteReportData::create($viewer ?? Security::getCurrentUser())->rows($filters) as $row) {
            $rows[$row->Title] = $row->toMap();
        }
        return $rows;
    }

    public function testItIsAReportOfTheReportsSection(): void
    {
        $this->assertInstanceOf(Report::class, SeoSiteReport::singleton());
        $this->assertArrayHasKey(SeoSiteReport::class, Report::get_reports());
    }

    public function testOnlyMembersWithThePermissionSeeTheReport(): void
    {
        $report = SeoSiteReport::singleton();
        $this->assertFalse($report->canView(false), 'not without a member');

        $reportsOnly = $this->member('CMS_ACCESS_ReportAdmin');
        $this->assertFalse($report->canView($reportsOnly), 'the Reports section alone is not enough');

        $reader = $this->member(self::READER);
        $this->assertTrue($report->canView($reader));

        $admin = $this->member('ADMIN');
        $this->assertTrue($report->canView($admin));

        $codes = array_keys(SeoSiteReport::singleton()->providePermissions());
        $this->assertSame(['VIEW_SEO_REPORT'], $codes);
        $this->assertSame('VIEW_SEO_REPORT', SeoSiteReport::PERMISSION);
    }

    public function testRowsHoldCountsPerSeverityAndStatus(): void
    {
        $this->logInWithPermission('ADMIN');
        $this->site();
        $rows = $this->rows();

        $this->assertSame(['Broken page', 'Clean page', 'Hidden page', 'Unchecked page', 'Warned page'], array_keys($this->sorted($rows)));
        $this->assertSame([1, 1, 1], [$rows['Broken page']['Problems'], $rows['Broken page']['Warnings'], $rows['Broken page']['Tips']]);
        $this->assertSame([0, 0, 1], [$rows['Clean page']['Problems'], $rows['Clean page']['Warnings'], $rows['Clean page']['Tips']]);
        $this->assertSame(SiteReportData::STATUS_FRESH, $rows['Broken page']['Status']);
        $this->assertSame(SiteReportData::STATUS_UNCHECKED, $rows['Unchecked page']['Status']);
        $this->assertSame('', (string) $rows['Unchecked page']['CheckedAt']);
        $this->assertSame('2026-11-25 11:00:00', $rows['Broken page']['CheckedAt']);
        $this->assertFalse($rows['Hidden page']['Indexable']);
        $this->assertTrue($rows['Broken page']['Indexable']);
        $this->assertStringContainsString('admin/pages/edit/show/', $rows['Broken page']['EditLink']);
        $this->assertStringContainsString('Main heading (H1)', $rows['Broken page']['FailingChecks']);
    }

    public function testAnIgnoredCheckIsNotCounted(): void
    {
        $this->logInWithPermission('ADMIN');
        $page = $this->publishedPage('Ignored page');
        SeoCheckIgnore::ignore($page, 'h1-missing', Security::getCurrentUser());
        $this->storeResult($page, ['h1-missing' => Check::WARNING]);
        $row = $this->rows()['Ignored page'];
        $this->assertSame(0, $row['Warnings']);
        $this->assertSame([], $this->rows(['Check' => 'h1-missing']));
    }

    public function testAStaleResultIsMarked(): void
    {
        $this->logInWithPermission('ADMIN');
        $pages = $this->site();
        # Saved after the check (bypassing the hooks would do the same through LastEdited)
        DBDatetime::set_mock_now('2026-11-25 12:00:00');
        $pages['warned']->Title = 'Warned page';
        $pages['warned']->Content = '<p>Changed.</p>';
        $pages['warned']->write();

        $rows = $this->rows();
        $this->assertSame(SiteReportData::STATUS_STALE, $rows['Warned page']['Status']);
        $this->assertSame(['Warned page'], array_keys($this->rows(['Status' => SiteReportData::STATUS_STALE])));
        $this->assertSame(['Unchecked page'], array_keys($this->rows(['Status' => SiteReportData::STATUS_UNCHECKED])));
        $this->assertSame(1, SiteReportData::create(Security::getCurrentUser())->summary()['stale']);
    }

    public function testFilters(): void
    {
        $this->logInWithPermission('ADMIN');
        $this->site();
        $this->assertSame(['Broken page'], array_keys($this->rows(['Severity' => Check::PROBLEM])));
        $this->assertSame(['Broken page', 'Hidden page', 'Warned page'], array_keys($this->sorted($this->rows(['Severity' => Check::WARNING]))));
        $this->assertSame(['Broken page', 'Clean page', 'Warned page'], array_keys($this->sorted($this->rows(['Severity' => Check::TIP]))));
        $this->assertSame(['Clean page', 'Hidden page', 'Warned page'], array_keys($this->sorted($this->rows(['Severity' => SiteReportData::SEVERITY_NONE]))));
        $this->assertSame(['Broken page', 'Warned page'], array_keys($this->sorted($this->rows(['Check' => 'h1-missing']))));
        $this->assertSame(['Broken page', 'Warned page'], array_keys($this->sorted($this->rows(['Check' => 'h1-missing', 'PageType' => SiteTree::class]))));
        $this->assertSame([], $this->rows(['PageType' => RedirectorPage::class]));
        # An unknown value filters nothing out rather than everything
        $this->assertCount(5, $this->rows(['Severity' => 'nonsense']));
    }

    public function testIneligiblePagesAreNotListed(): void
    {
        $this->logInWithPermission('ADMIN');
        RedirectorPage::create(['Title' => 'Elsewhere', 'ExternalURL' => 'https://example.com'])->write();
        $this->publishedPage('A page');
        $this->assertSame(['A page'], array_keys($this->rows()));
    }

    public function testPagesAreListedOnlyWhenTheViewerMayViewThem(): void
    {
        $this->site();
        $group = Group::create(['Title' => 'Others', 'Code' => 'others']);
        $group->write();
        $private = $this->publishedPage('Private page', ['CanViewType' => 'OnlyTheseUsers']);
        $private->ViewerGroups()->add($group);
        $private->write();
        $private->publishSingle();
        $this->storeResult($private, ['title-missing' => Check::PROBLEM]);

        $reader = $this->member(self::READER);
        $rows = $this->rows([], $reader);
        $this->assertArrayNotHasKey('Private page', $rows);
        $this->assertArrayHasKey('Broken page', $rows);
        # Nor do its failing checks count in the summary
        $counts = array_column(SiteReportData::create($reader)->summary()['topChecks'], 'count', 'id');
        $this->assertSame(1, $counts['title-missing'] ?? null, 'title-missing on one visible page');

        $admin = $this->member('ADMIN');
        $this->assertArrayHasKey('Private page', $this->rows([], $admin));
    }

    public function testAReaderWithoutDraftRightsSeesTheLiveTitle(): void
    {
        $page = $this->publishedPage('Live title');
        $page->Title = 'Unpublished title';
        $page->write();
        $this->storeResult($page, []);

        $reader = $this->member(self::READER);
        $this->assertSame(['Live title'], array_keys($this->rows([], $reader)), 'the draft title is not shown');

        $admin = $this->member('ADMIN');
        $this->assertSame(['Unpublished title'], array_keys($this->rows([], $admin)));
    }

    public function testDetailsOnlyForPagesTheViewerMayEdit(): void
    {
        $this->site();
        $reader = $this->member(self::READER);
        $rows = $this->rows([], $reader);
        # A reader without edit rights sees the counts, not which checks fail, and gets no edit link
        $this->assertSame(1, $rows['Broken page']['Problems']);
        $this->assertSame('', $rows['Broken page']['FailingChecks']);
        $this->assertSame('', $rows['Broken page']['EditLink']);
        $this->assertSame([], $this->rows(['Check' => 'h1-missing'], $reader), 'the check filter reads details');
    }

    public function testTheSummary(): void
    {
        $this->logInWithPermission('ADMIN');
        $this->site();
        $summary = SiteReportData::create(Security::getCurrentUser())->summary();

        # The noindex page is not indexable; the unchecked one is indexable but not "without problems"
        $this->assertSame(4, $summary['indexable']);
        $this->assertSame(2, $summary['withoutProblems']);
        $this->assertSame(1, $summary['unchecked']);
        $this->assertSame(0, $summary['stale']);
        $top = array_map(fn ($c) => [$c['id'], $c['count']], $summary['topChecks']);
        $this->assertSame([['og-image', 3], ['h1-missing', 2], ['title-missing', 1]], $top);
        $this->assertSame('Sharing image', $summary['topChecks'][0]['title']);
        $this->assertArrayNotHasKey('score', $summary);
    }

    public function testTheReportShowsTheSummaryWithoutAScore(): void
    {
        $this->logInWithPermission('ADMIN');
        $this->site();
        $fields = SeoSiteReport::singleton()->getCMSFields();
        $summary = $fields->dataFieldByName('SeoReportSummary') ?? $fields->fieldByName('SeoReportSummary');
        $this->assertNotNull($summary);
        $html = (string) $summary->getContent();
        $this->assertStringContainsString('2 of 4', $html);
        $this->assertStringContainsString('Sharing image', $html);
        $this->assertStringNotContainsStringIgnoringCase('score', $html);
    }

    public function testSortingTheRecords(): void
    {
        $this->logInWithPermission('ADMIN');
        $this->site();
        $titles = SeoSiteReport::singleton()->sourceRecords([], 'Problems DESC, Warnings DESC, Title ASC', null)->column('Title');
        $this->assertSame(['Broken page', 'Hidden page', 'Warned page', 'Clean page', 'Unchecked page'], $titles);
    }

    public function testCsvCellsAreEscapedAgainstFormulaInjection(): void
    {
        foreach (['=1+1', '+1', '-1', '@SUM(A1)', "\t=1", "\r=1"] as $value) {
            $this->assertSame("'" . $value, SeoReportCsv::escapeCell($value), json_encode($value));
        }
        $this->assertSame('Plain', SeoReportCsv::escapeCell('Plain'));
        $this->assertSame('3', SeoReportCsv::escapeCell(3));
        $this->assertSame('a=b', SeoReportCsv::escapeCell('a=b'));
    }

    public function testTheExportUsesTheEscapingButton(): void
    {
        $this->logInWithPermission('ADMIN');
        $this->site();
        $evil = $this->publishedPage('=HYPERLINK("https://example.com","x")');
        $this->storeResult($evil, ['title-missing' => Check::PROBLEM]);

        $grid = SeoSiteReport::singleton()->getReportField();
        $this->assertInstanceOf(GridField::class, $grid);
        $buttons = $grid->getConfig()->getComponentsByType(GridFieldExportButton::class);
        $this->assertCount(1, $buttons);
        $button = $buttons->first();
        $this->assertInstanceOf(SeoReportExportButton::class, $button);

        $csv = $button->generateExportFileData($grid);
        $lines = array_values(array_filter(preg_split('/\r\n/', ltrim($csv, "\xEF\xBB\xBF"))));
        $header = str_getcsv($lines[0], ',', '"', '');
        $this->assertContains('Problems', $header);
        $this->assertContains('Failing checks', $header);
        $this->assertNotContains('Score', $header);
        $this->assertCount(7, $lines, 'a header and six pages');
        $this->assertStringContainsString("'=HYPERLINK", $csv);
        $this->assertStringNotContainsString("\t=HYPERLINK", $csv, 'not the core tab prefix on top');
        $broken = null;
        foreach ($lines as $line) {
            if (str_starts_with($line, 'Broken page') || str_starts_with($line, '"Broken page"')) {
                $broken = str_getcsv($line, ',', '"', '');
            }
        }
        $this->assertNotNull($broken);
        $this->assertSame('1', $broken[array_search('Problems', $header)]);
    }

    /**
     * Log in a member with the codes and return the member (logInWithPermission() returns the ID).
     */
    protected function member(string|array $codes): Member
    {
        return Member::get()->byID($this->logInWithPermission($codes));
    }

    /**
     * @param array<string, mixed> $rows
     * @return array<string, mixed>
     */
    protected function sorted(array $rows): array
    {
        ksort($rows);
        return $rows;
    }
}
