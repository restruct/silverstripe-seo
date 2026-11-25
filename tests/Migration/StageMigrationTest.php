<?php

namespace Restruct\Silverstripe\SEO\Tests\Migration;

use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\ORM\DB;

/**
 * Writing per stage: a draft-only page stays draft-only, and nothing is published or versioned.
 */
class StageMigrationTest extends SeoMigrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->addOldStagedColumn('Page', 'SEOSocialImageID', 'int NOT NULL DEFAULT 0');
    }

    public function testADraftOnlyPageGetsNoLiveRow(): void
    {
        $image = $this->createTestImage('social.png');
        $page = SiteTree::create(['Title' => 'Draft only']);
        $page->write();
        $this->setRaw('Page', $page->ID, ['SEOSocialImageID' => $image->ID]);

        $output = $this->runMigration('hubertusanton', ['--apply' => true]);

        $this->assertReportLine($output, 'SEOSocialImageID -> OGImageID [draft]:', ['copied 1']);
        $this->assertReportLine($output, 'SEOSocialImageID -> OGImageID [live]:', ['read 0']);
        $this->assertSame($image->ID, (int) $this->raw('SiteTree', 'OGImageID', $page->ID));
        $this->assertNull($this->raw('SiteTree_Live', 'ID', $page->ID));
    }

    public function testNothingIsPublishedAndNoVersionIsWritten(): void
    {
        $image = $this->createTestImage('social.png');
        $page = $this->publishedPage('Courses');
        foreach (['Page', 'Page_Live'] as $table) {
            $this->setRaw($table, $page->ID, ['SEOSocialImageID' => $image->ID]);
        }
        $versions = fn () => (int) DB::prepared_query(
            'SELECT COUNT(*) FROM "SiteTree_Versions" WHERE "RecordID" = ?',
            [$page->ID]
        )->value();
        $before = [$this->raw('SiteTree', 'Version', $page->ID), $this->raw('SiteTree_Live', 'Version', $page->ID), $versions()];

        $this->runMigration('hubertusanton', ['--apply' => true]);

        $after = [$this->raw('SiteTree', 'Version', $page->ID), $this->raw('SiteTree_Live', 'Version', $page->ID), $versions()];
        $this->assertSame($before, $after);
        $this->assertSame($image->ID, (int) $this->raw('SiteTree_Live', 'OGImageID', $page->ID));
        # Draft and live are still the same version: the page does not show as modified
        $this->assertFalse(SiteTree::get()->byID($page->ID)->isModifiedOnDraft());
    }
}
