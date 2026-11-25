<?php

namespace Restruct\Silverstripe\SEO\Tests\Migration;

use Symfony\Component\Console\Command\Command;

/**
 * With Fluent, page and site values live per locale in _Localised tables, which the migration does not handle
 * yet: it reports, and refuses to write.
 */
class FluentMigrationTest extends SeoMigrationTestCase
{
    /**
     * @return array{0: int, 1: int} page ID, image ID
     */
    private function seed(string $localisedTable): array
    {
        $this->addOldStagedColumn('Page', 'SEOSocialImageID', 'int NOT NULL DEFAULT 0');
        $image = $this->createTestImage('social.png');
        $page = $this->publishedPage('Courses');
        $this->setRaw('Page', $page->ID, ['SEOSocialImageID' => $image->ID]);
        $this->createOldTable($localisedTable, ['RecordID' => 'int NOT NULL DEFAULT 0', 'Locale' => 'varchar(10)']);
        return [$page->ID, $image->ID];
    }

    public function testApplyStopsBeforeWritingWhenPagesAreLocalised(): void
    {
        [$pageID] = $this->seed('SiteTree_Localised');

        $output = $this->runMigration('hubertusanton', ['--apply' => true], Command::FAILURE);

        $this->assertReportLine($output, 'Warning:', ['SiteTree_Localised', 'Fluent', 'not supported']);
        $this->assertSame(0, (int) $this->raw('SiteTree', 'OGImageID', $pageID));
    }

    public function testApplyStopsWhenSiteConfigIsLocalised(): void
    {
        [$pageID] = $this->seed('SiteConfig_Localised');

        $output = $this->runMigration('hubertusanton', ['--apply' => true], Command::FAILURE);

        $this->assertReportLine($output, 'Warning:', ['SiteConfig_Localised']);
        $this->assertSame(0, (int) $this->raw('SiteTree', 'OGImageID', $pageID));
    }

    public function testADryRunStillReportsWithTheWarning(): void
    {
        $this->seed('SiteTree_Localised');

        $output = $this->runMigration('hubertusanton');

        $this->assertReportLine($output, 'Warning:', ['SiteTree_Localised', 'Fluent']);
        $this->assertReportLine($output, 'SEOSocialImageID -> OGImageID [draft]:', ['would copy 1']);
    }
}
