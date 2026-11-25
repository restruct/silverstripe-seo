<?php

namespace Restruct\Silverstripe\SEO\Tests\Checks;

use Restruct\Silverstripe\SEO\Checks\CheckRunner;
use Restruct\Silverstripe\SEO\Forms\SeoChecksField;
use Restruct\Silverstripe\SEO\Model\SeoCheckResult;
use Restruct\Silverstripe\SEO\Tests\Stub\Checks\SeoCheckTestPage;
use SilverStripe\ORM\DB;
use SilverStripe\ORM\FieldType\DBDatetime;
use SilverStripe\Security\Security;

/**
 * A save that only changed blocks does not write the page, so the page's own onAfterWrite never marks the
 * stored result stale. With Elemental installed, element writes, publishes and deletes do; and the panel also
 * compares the page's and its elements' LastEdited with CheckedAt, for writes that bypass the hooks.
 */
class BlockSaveStaleTest extends ChecksTestCase
{
    private const ELEMENTAL_PAGE = 'DNADesign\\Elemental\\Extensions\\ElementalPageExtension';
    private const ELEMENT = 'DNADesign\\Elemental\\Models\\ElementContent';

    protected static $required_extensions = [
        SeoCheckTestPage::class => [self::ELEMENTAL_PAGE],
    ];

    public static function setUpBeforeClass(): void
    {
        if (!class_exists(self::ELEMENTAL_PAGE)) {
            static::markTestSkipped('dnadesign/silverstripe-elemental is not installed');
        }
        parent::setUpBeforeClass();
    }

    protected function tearDown(): void
    {
        DBDatetime::clear_mock_now();
        parent::tearDown();
    }

    private function checkedPage(): SeoCheckTestPage
    {
        DBDatetime::set_mock_now('2026-11-25 10:00:00');
        $page = $this->page();
        CheckRunner::singleton()->checkPage($page, Security::getCurrentUser());
        $this->assertFalse((bool) SeoCheckResult::forPage($page)->Stale);
        DBDatetime::set_mock_now('2026-11-25 10:05:00');
        return $page;
    }

    private function element(SeoCheckTestPage $page)
    {
        $class = self::ELEMENT;
        $element = $class::create(['Title' => 'Intro', 'HTML' => '<p>Changed text.</p>', 'ParentID' => $page->ElementalAreaID]);
        $element->write();
        return $element;
    }

    public function testWritingABlockMarksTheResultStale(): void
    {
        $page = $this->checkedPage();
        $pageEdited = DB::query('SELECT "LastEdited" FROM "SiteTree" WHERE "ID" = ' . (int) $page->ID)->value();
        $this->element($page);
        $this->assertSame($pageEdited, DB::query('SELECT "LastEdited" FROM "SiteTree" WHERE "ID" = ' . (int) $page->ID)->value(), 'the page itself was not written');
        $this->assertTrue((bool) SeoCheckResult::forPage($page)->Stale);
    }

    public function testPublishingOrDeletingABlockMarksTheResultStale(): void
    {
        $page = $this->checkedPage();
        $element = $this->element($page);
        CheckRunner::singleton()->checkPage($page, Security::getCurrentUser());
        $element->publishSingle();
        $this->assertTrue((bool) SeoCheckResult::forPage($page)->Stale, 'publish');

        CheckRunner::singleton()->checkPage($page, Security::getCurrentUser());
        $element->doArchive();
        $this->assertTrue((bool) SeoCheckResult::forPage($page)->Stale, 'delete');
    }

    public function testThePanelComparesLastEditedForWritesThatBypassTheHooks(): void
    {
        $page = $this->checkedPage();
        $element = $this->element($page);
        CheckRunner::singleton()->checkPage($page, Security::getCurrentUser());
        $this->assertFalse(SeoChecksField::create('SeoChecks', '', $page)->getStale());

        # A block changed by a query, after the check
        DB::prepared_query('UPDATE "Element" SET "LastEdited" = ? WHERE "ID" = ?', ['2026-11-25 11:00:00', $element->ID]);
        $this->assertTrue(SeoChecksField::create('SeoChecks', '', $page)->getStale(), 'block newer');

        CheckRunner::singleton()->checkPage($page, Security::getCurrentUser());
        DB::prepared_query('UPDATE "SiteTree" SET "LastEdited" = ? WHERE "ID" = ?', ['2026-11-25 12:00:00', $page->ID]);
        $this->assertTrue(SeoChecksField::create('SeoChecks', '', $page)->getStale(), 'page newer');
    }
}
