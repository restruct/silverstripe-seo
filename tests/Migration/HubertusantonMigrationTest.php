<?php

namespace Restruct\Silverstripe\SEO\Tests\Migration;

use Symfony\Component\Console\Command\Command;

/**
 * hubertusanton/silverstripe-seo: its columns live on the Page table (the module extends Page, not SiteTree).
 */
class HubertusantonMigrationTest extends SeoMigrationTestCase
{
    private function addColumns(): void
    {
        $this->addOldStagedColumn('Page', 'SEOPageSubject', 'varchar(256)');
        $this->addOldStagedColumn('Page', 'SEOSocialType', 'varchar(50)');
        $this->addOldStagedColumn('Page', 'SEOHideSocialData', 'tinyint(1) unsigned NOT NULL DEFAULT 0');
        $this->addOldStagedColumn('Page', 'SEOSocialImageID', 'int NOT NULL DEFAULT 0');
        $this->addOldColumn('SiteConfig', 'GoogleWebmasterMetaTag', 'varchar(512)');
    }

    public function testDryRunReportsAndWritesNothing(): void
    {
        $this->addColumns();
        $draftImage = $this->createTestImage('draft.png');
        $liveImage = $this->createTestImage('live.png');
        $page = $this->publishedPage('Courses');
        $this->setRaw('Page', $page->ID, ['SEOSocialImageID' => $draftImage->ID, 'SEOHideSocialData' => 1]);
        $this->setRaw('Page_Live', $page->ID, ['SEOSocialImageID' => $liveImage->ID]);

        $output = $this->runMigration('hubertusanton');

        $this->assertStringContainsString('Dry run', $output);
        $this->assertReportLine($output, 'SEOSocialImageID -> OGImageID [draft]:', ['read 1', 'would copy 1']);
        $this->assertReportLine($output, 'SEOSocialImageID -> OGImageID [live]:', ['read 1', 'would copy 1']);
        $this->assertReportLine($output, 'SEOHideSocialData [draft]:', ['no equivalent', '1 values', 'seo_output_open_graph']);
        $this->assertReportLine($output, 'SEOPageSubject [draft]:', ['dropped']);
        $this->assertReportLine($output, 'SEOSocialType [draft]:', ['dropped']);
        $this->assertReportLine($output, 'SiteConfig.GoogleWebmasterMetaTag:', ['no equivalent']);
        $this->assertSame(0, (int) $this->raw('SiteTree', 'OGImageID', $page->ID));
        $this->assertSame(0, (int) $this->raw('SiteTree_Live', 'OGImageID', $page->ID));
    }

    public function testApplyCopiesEachStageKeepsFilledTargetsAndIsIdempotent(): void
    {
        $this->addColumns();
        $draftImage = $this->createTestImage('draft.png');
        $liveImage = $this->createTestImage('live.png');
        $ownImage = $this->createTestImage('own.png');

        $copied = $this->publishedPage('Courses');
        $this->setRaw('Page', $copied->ID, ['SEOSocialImageID' => $draftImage->ID]);
        $this->setRaw('Page_Live', $copied->ID, ['SEOSocialImageID' => $liveImage->ID]);

        # Already has a sharing image of its own here: kept
        $kept = $this->publishedPage('News', ['OGImageID' => $ownImage->ID]);
        $kept->publishSingle();
        $this->setRaw('Page', $kept->ID, ['SEOSocialImageID' => $draftImage->ID]);
        $this->setRaw('Page_Live', $kept->ID, ['SEOSocialImageID' => $draftImage->ID]);

        # Points at a file that no longer exists: not copied
        $broken = $this->publishedPage('Old');
        $this->setRaw('Page', $broken->ID, ['SEOSocialImageID' => 999999]);

        $output = $this->runMigration('hubertusanton', ['--apply' => true]);

        $this->assertReportLine($output, 'SEOSocialImageID -> OGImageID [draft]:', [
            'read 3', 'copied 1', 'already same 0', 'conflicts 1 (kept)', 'invalid 1',
        ]);
        $this->assertReportLine($output, 'SEOSocialImageID -> OGImageID [live]:', ['read 2', 'copied 1', 'conflicts 1']);
        # Each stage gets its own stage's value: the live page is not given the draft image
        $this->assertSame($draftImage->ID, (int) $this->raw('SiteTree', 'OGImageID', $copied->ID));
        $this->assertSame($liveImage->ID, (int) $this->raw('SiteTree_Live', 'OGImageID', $copied->ID));
        $this->assertSame($ownImage->ID, (int) $this->raw('SiteTree', 'OGImageID', $kept->ID));
        $this->assertSame($ownImage->ID, (int) $this->raw('SiteTree_Live', 'OGImageID', $kept->ID));
        $this->assertSame(0, (int) $this->raw('SiteTree', 'OGImageID', $broken->ID));

        $again = $this->runMigration('hubertusanton', ['--apply' => true]);
        $this->assertReportLine($again, 'SEOSocialImageID -> OGImageID [draft]:', ['copied 0', 'already same 1', 'conflicts 1']);
        $this->assertReportLine($again, 'SEOSocialImageID -> OGImageID [live]:', ['copied 0', 'already same 1']);
    }

    public function testColumnsLeftBehindInAnObsoleteTableAreFound(): void
    {
        # db:build renames Page to _obsolete_Page once no class gives that table a field any more
        $this->addOldStagedColumn('_obsolete_Page', 'SEOSocialImageID', 'int NOT NULL DEFAULT 0');
        $image = $this->createTestImage('draft.png');
        $page = $this->publishedPage('Courses');
        $this->setRaw('_obsolete_Page', $page->ID, ['SEOSocialImageID' => $image->ID]);
        $this->setRaw('_obsolete_Page_Live', $page->ID, ['SEOSocialImageID' => $image->ID]);

        $output = $this->runMigration('hubertusanton', ['--apply' => true]);

        $this->assertReportLine($output, 'SEOSocialImageID -> OGImageID [live]:', ['copied 1']);
        $this->assertSame($image->ID, (int) $this->raw('SiteTree_Live', 'OGImageID', $page->ID));
    }

    public function testMissingColumnsAreSkippedWithANotice(): void
    {
        $output = $this->runMigration('hubertusanton', ['--apply' => true]);

        $this->assertReportLine($output, 'SEOSocialImageID -> OGImageID:', ['not found', 'skipped']);
        $this->assertReportLine($output, 'SiteConfig.GoogleWebmasterMetaTag:', ['not found']);
    }

    public function testTheSourceIsRequiredAndChecked(): void
    {
        $output = $this->runMigration(null, [], Command::INVALID);
        $this->assertStringContainsString('--source', $output);
        $this->assertStringContainsString('hubertusanton', $output);

        $output = $this->runMigration('no-such-module', [], Command::INVALID);
        $this->assertStringContainsString('no-such-module', $output);
        $this->assertStringContainsString('liveseo', $output);
    }
}
