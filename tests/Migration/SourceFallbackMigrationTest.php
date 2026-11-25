<?php

namespace Restruct\Silverstripe\SEO\Tests\Migration;

/**
 * With several source columns, one whose value turns out unusable gives way to the next.
 */
class SourceFallbackMigrationTest extends SeoMigrationTestCase
{
    public function testAnUnusableFirstValueFallsThroughToTheNext(): void
    {
        $this->addOldStagedColumn('Page', 'FacebookPageImageID', 'int NOT NULL DEFAULT 0');
        $this->addOldStagedColumn('Page', 'TwitterPageImageID', 'int NOT NULL DEFAULT 0');
        $twitter = $this->createTestImage('twitter.png');
        $page = $this->publishedPage('Courses');
        # The Facebook image no longer exists
        $this->setRaw('Page', $page->ID, ['FacebookPageImageID' => 99999, 'TwitterPageImageID' => $twitter->ID]);

        $output = $this->runMigration('jellygnite', ['--apply' => true]);

        $this->assertReportLine($output, 'FacebookPageImageID, TwitterPageImageID -> OGImageID [draft]:', [
            'read 1', 'copied 1', 'invalid 0',
        ]);
        $this->assertSame($twitter->ID, (int) $this->raw('SiteTree', 'OGImageID', $page->ID));
    }
}
