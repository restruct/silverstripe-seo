<?php

namespace Restruct\Silverstripe\SEO\Tests\Report;

use ReflectionProperty;
use Restruct\Silverstripe\SEO\Checks\Check;
use Restruct\Silverstripe\SEO\Checks\CheckResult;
use Restruct\Silverstripe\SEO\Checks\CheckRunner;
use Restruct\Silverstripe\SEO\Checks\Rules\ThinContentCheck;
use Restruct\Silverstripe\SEO\Model\SeoCheckResult;
use Restruct\Silverstripe\SEO\Model\StaleContext;
use Restruct\Silverstripe\SEO\Tests\Checks\ChecksTestCase;
use SilverStripe\ORM\DB;
use SilverStripe\ORM\FieldType\DBDatetime;
use SilverStripe\Security\Security;
use SilverStripe\Versioned\Versioned;

/**
 * When a stored result is out of date beyond its own page: checks that read other pages (duplicates, links,
 * inbound links) go stale when any page changes, and every result goes stale when the check configuration
 * changes. The report decides staleness for all rows with a fixed number of queries.
 */
class StalenessTest extends ChecksTestCase
{
    protected function tearDown(): void
    {
        DBDatetime::clear_mock_now();
        parent::tearDown();
    }

    /**
     * A result with one failing entry of the given check id, as the runner stores it.
     */
    protected function storeEntry($page, string $id, bool $crossPage): SeoCheckResult
    {
        return SeoCheckResult::store($page, Versioned::DRAFT, [$id => [
            'id' => $id,
            'severity' => Check::WARNING,
            'status' => CheckResult::FAIL,
            'title' => $id,
            'crossPage' => $crossPage,
            'ignored' => false,
        ]]);
    }

    public function testTheCrossPageChecksSayTheyAre(): void
    {
        $checks = CheckRunner::singleton()->getChecks();
        foreach (['title-duplicate', 'description-duplicate', 'broken-links', 'unpublished-links', 'orphan-page', 'link-redirects'] as $id) {
            $this->assertTrue($checks[$id]->isCrossPage(), "$id reads other pages");
        }
        foreach (['h1-missing', 'thin-content', 'title-width'] as $id) {
            $this->assertFalse($checks[$id]->isCrossPage(), "$id reads its own page only");
        }
    }

    public function testTheRunnerMarksAResultThatHoldsACrossPageCheck(): void
    {
        $result = CheckRunner::singleton()->checkPage($this->page(), Security::getCurrentUser());
        $this->assertTrue((bool) $result->CrossPage);
        $this->assertTrue($result->getEntries()['broken-links']['crossPage']);
        $this->assertFalse($result->getEntries()['h1-missing']['crossPage']);
    }

    public function testACrossPageResultIsStaleWhenAnyPageChangedAfterIt(): void
    {
        DBDatetime::set_mock_now('2026-11-25 10:00:00');
        $page = $this->page();
        $other = $this->page();
        DBDatetime::set_mock_now('2026-11-25 11:00:00');
        $cross = $this->storeEntry($page, 'title-duplicate', true);
        $own = $this->storeEntry($other, 'h1-missing', false);
        $this->assertFalse($cross->isStale());
        $this->assertFalse($own->isStale());

        # A third page is saved: the duplicate check on the first may now be wrong, the H1 check on the second not
        DBDatetime::set_mock_now('2026-11-25 12:00:00');
        $this->page();
        $this->assertTrue(SeoCheckResult::forPage($page)->isStale());
        $this->assertFalse(SeoCheckResult::forPage($other)->isStale());
    }

    public function testEveryResultIsStaleWhenTheCheckConfigChanges(): void
    {
        DBDatetime::set_mock_now('2026-11-25 10:00:00');
        $page = $this->page();
        DBDatetime::set_mock_now('2026-11-25 11:00:00');
        $result = $this->storeEntry($page, 'h1-missing', false);
        $this->assertNotSame('', (string) $result->ConfigHash);
        $this->assertFalse($result->isStale());

        ThinContentCheck::config()->set('min_words', 500);
        $this->assertTrue(SeoCheckResult::forPage($page)->isStale(), 'a threshold changed');
        ThinContentCheck::config()->set('min_words', 50);
        $this->assertFalse(SeoCheckResult::forPage($page)->isStale(), 'back to what it was checked with');

        CheckRunner::config()->merge('checks', ['thin-content' => ['enabled' => false]]);
        $this->assertTrue(SeoCheckResult::forPage($page)->isStale(), 'a check was switched off');
    }

    public function testARowOfAnEarlierVersionWithoutAHashIsStale(): void
    {
        DBDatetime::set_mock_now('2026-11-25 10:00:00');
        $page = $this->page();
        DBDatetime::set_mock_now('2026-11-25 11:00:00');
        $result = $this->storeEntry($page, 'h1-missing', false);
        DB::prepared_query('UPDATE "SeoCheckResult" SET "ConfigHash" = ? WHERE "ID" = ?', ['', $result->ID]);
        $this->assertTrue(SeoCheckResult::forPage($page)->isStale());
    }

    public function testAPreloadedContextDecidesStalenessWithAFixedNumberOfQueries(): void
    {
        DBDatetime::set_mock_now('2026-11-25 10:00:00');
        $pages = [];
        for ($i = 0; $i < 6; $i++) {
            $pages[] = $this->page();
        }
        DBDatetime::set_mock_now('2026-11-25 11:00:00');
        $results = [];
        foreach ($pages as $page) {
            $results[] = $this->storeEntry($page, 'h1-missing', false);
        }

        $two = $this->queries(fn () => StaleContext::create(Versioned::DRAFT)->preload(array_slice($pages, 0, 2)));
        $context = null;
        $six = $this->queries(function () use ($pages, &$context) {
            $context = StaleContext::create(Versioned::DRAFT)->preload($pages);
        });
        $this->assertSame($two, $six, 'the same number of queries for two pages and for six');
        $this->assertLessThanOrEqual(3, $six);

        $stale = null;
        $none = $this->queries(function () use ($results, $context, &$stale) {
            $stale = array_map(fn (SeoCheckResult $r) => $r->isStale($context), $results);
        });
        $this->assertSame(0, $none, 'no query per result');
        $this->assertSame(array_fill(0, 6, false), $stale);
    }

    /**
     * How many queries $fn runs.
     */
    protected function queries(callable $fn): int
    {
        $conn = DB::get_conn();
        $count = new ReflectionProperty(get_class($conn), 'queryCount');
        # Core counts queries only while showqueries is set; the whitelist mode counts without printing
        $_REQUEST['showqueries'] = 'whitelist';
        $before = $count->getValue($conn);
        try {
            $fn();
        } finally {
            unset($_REQUEST['showqueries']);
        }
        return $count->getValue($conn) - $before;
    }
}
