<?php

namespace Restruct\Silverstripe\SEO\Tests\Migration;

/**
 * The live page is never pointed at an image that is not published: it is reported instead.
 */
class UnpublishedFileMigrationTest extends SeoMigrationTestCase
{
    public function testAnUnpublishedImageIsCopiedToDraftOnlyAndReported(): void
    {
        $this->addOldStagedColumn('Page', 'SEOSocialImageID', 'int NOT NULL DEFAULT 0');
        $image = $this->createTestImage('draft-only.png');
        $image->doUnpublish();
        $page = $this->publishedPage('Courses');
        $this->setRaw('Page', $page->ID, ['SEOSocialImageID' => $image->ID]);
        $this->setRaw('Page_Live', $page->ID, ['SEOSocialImageID' => $image->ID]);

        $output = $this->runMigration('hubertusanton', ['--apply' => true]);

        $this->assertReportLine($output, 'SEOSocialImageID -> OGImageID [draft]:', ['copied 1']);
        $this->assertReportLine($output, 'SEOSocialImageID -> OGImageID [live]:', ['read 1', 'copied 0', 'unpublished 1']);
        $this->assertSame($image->ID, (int) $this->raw('SiteTree', 'OGImageID', $page->ID));
        $this->assertSame(0, (int) $this->raw('SiteTree_Live', 'OGImageID', $page->ID));
    }
}
