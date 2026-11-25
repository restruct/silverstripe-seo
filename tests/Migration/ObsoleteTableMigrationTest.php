<?php

namespace Restruct\Silverstripe\SEO\Tests\Migration;

/**
 * db:build renames a table it no longer needs to _obsolete_<table>, and on a name clash adds a number after the
 * whole name: _obsolete_Page2, and _obsolete_Page_Live2 for Page_Live (DBSchemaManager::dontRequireTable()).
 */
class ObsoleteTableMigrationTest extends SeoMigrationTestCase
{
    public function testTheLatestRenamedTableWinsWithAWarning(): void
    {
        $older = $this->createTestImage('older.png');
        $newer = $this->createTestImage('newer.png');
        $page = $this->publishedPage('Courses');
        foreach (['_obsolete_Page', '_obsolete_Page2'] as $table) {
            $this->addOldStagedColumn($table, 'SEOSocialImageID', 'int NOT NULL DEFAULT 0');
        }
        $this->setRaw('_obsolete_Page', $page->ID, ['SEOSocialImageID' => $older->ID]);
        $this->setRaw('_obsolete_Page2', $page->ID, ['SEOSocialImageID' => $newer->ID]);

        $output = $this->runMigration('hubertusanton', ['--apply' => true]);

        $this->assertSame($newer->ID, (int) $this->raw('SiteTree', 'OGImageID', $page->ID));
        $this->assertReportLine($output, 'Warning:', ['SEOSocialImageID', '_obsolete_Page', '_obsolete_Page2']);
    }

    public function testLiveTablesAreMatchedByTheirOwnNames(): void
    {
        $image = $this->createTestImage('live.png');
        $page = $this->publishedPage('Courses');
        # The draft table was renamed once, its live table twice: the numbers differ
        $this->addOldColumn('_obsolete_Page', 'SEOSocialImageID', 'int NOT NULL DEFAULT 0');
        $this->addOldColumn('_obsolete_Page_Live2', 'SEOSocialImageID', 'int NOT NULL DEFAULT 0');
        $this->setRaw('_obsolete_Page_Live2', $page->ID, ['SEOSocialImageID' => $image->ID]);

        $output = $this->runMigration('hubertusanton', ['--apply' => true]);

        $this->assertReportLine($output, 'SEOSocialImageID -> OGImageID [live]:', ['read 1', 'copied 1']);
        $this->assertSame($image->ID, (int) $this->raw('SiteTree_Live', 'OGImageID', $page->ID));
    }
}
