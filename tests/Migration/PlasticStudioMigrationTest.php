<?php

namespace Restruct\Silverstripe\SEO\Tests\Migration;

/**
 * plasticstudio/silverstripe-seo (and the forks that share its schema): page columns on the Page table, a robots
 * string, and site-wide images on SiteConfig.
 */
class PlasticStudioMigrationTest extends SeoMigrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->addOldStagedColumn('Page', 'MetaTitle', 'varchar(512)');
        $this->addOldStagedColumn('Page', 'Canonical', 'varchar(512)');
        $this->addOldStagedColumn('Page', 'Robots', 'varchar(100)');
        $this->addOldStagedColumn('Page', 'SocialImageID', 'int NOT NULL DEFAULT 0');
        $this->addOldStagedColumn('Page', 'Priority', 'decimal(3,2)');
        $this->addOldStagedColumn('Page', 'ManualSchema', 'mediumtext');
        $this->addOldColumn('SiteConfig', 'DefaultSocialImageID', 'int NOT NULL DEFAULT 0');
        $this->addOldColumn('SiteConfig', 'SchemaOrganisationImageID', 'int NOT NULL DEFAULT 0');
        $this->addOldColumn('SiteConfig', 'SchemaOrganisationName', 'varchar(512)');
        $this->createOldTable('SeoHeadTag', [
            'Title' => 'varchar(512)', 'Value' => 'varchar(512)', 'Type' => 'varchar(512)',
        ]);
    }

    /**
     * @return array{0: \SilverStripe\CMS\Model\SiteTree, 1: \SilverStripe\CMS\Model\SiteTree, 2: \SilverStripe\CMS\Model\SiteTree, 3: int}
     */
    private function seed(): array
    {
        $image = $this->createTestImage('social.png');

        $copied = $this->publishedPage('Courses');
        $this->setRaw('Page', $copied->ID, [
            'MetaTitle' => 'All our courses',
            'Canonical' => 'https://www.example.com/courses',
            'Robots' => 'noindex,nofollow',
            'SocialImageID' => $image->ID,
            'Priority' => 0.8,
        ]);
        # The live version still has the old robots value: only the draft becomes noindex
        $this->setRaw('Page_Live', $copied->ID, [
            'MetaTitle' => 'All our courses',
            'Robots' => 'index,follow',
            'SocialImageID' => $image->ID,
        ]);

        $kept = $this->publishedPage('News', ['MetaTitle' => 'Kept title', 'MetaRobotsNoIndex' => true]);
        $kept->publishSingle();
        $this->setRaw('Page', $kept->ID, ['MetaTitle' => 'Other title', 'Robots' => 'noindex']);
        $this->setRaw('Page_Live', $kept->ID, ['MetaTitle' => 'Other title', 'Robots' => 'noindex']);

        $invalid = $this->publishedPage('Old');
        $this->setRaw('Page', $invalid->ID, ['Canonical' => 'not a url', 'Robots' => 'none']);

        $this->setRaw('SiteConfig', $this->siteConfigID(), [
            'DefaultSocialImageID' => $image->ID,
            'SchemaOrganisationImageID' => $image->ID,
            'SchemaOrganisationName' => 'Example Organisation',
        ]);
        $this->insertRaw('SeoHeadTag', ['ID' => 1, 'Title' => 'verify', 'Value' => 'abc', 'Type' => 'name']);

        return [$copied, $kept, $invalid, $image->ID];
    }

    public function testDryRunReportsAndWritesNothing(): void
    {
        [$copied] = $this->seed();

        $output = $this->runMigration('plasticstudio');

        $this->assertStringContainsString('Dry run', $output);
        $this->assertReportLine($output, 'MetaTitle -> MetaTitle [draft]:', ['read 2', 'would copy 1', 'conflicts 1']);
        $this->assertReportLine($output, 'Canonical -> CanonicalURL [draft]:', ['read 2', 'would copy 1', 'invalid 1']);
        $this->assertReportLine($output, 'Robots -> MetaRobotsNoIndex [draft]:', ['would copy 2', 'already same 1']);
        $this->assertReportLine($output, 'Robots -> MetaRobotsNoIndex [live]:', ['would copy 0', 'already same 1']);
        $this->assertReportLine($output, 'Robots -> MetaRobotsNoFollow [draft]:', ['would copy 2']);
        $this->assertReportLine($output, 'SocialImageID -> OGImageID [draft]:', ['would copy 1']);
        $this->assertReportLine($output, 'SiteConfig.DefaultSocialImageID -> SeoDefaultImageID:', ['would copy 1']);
        $this->assertReportLine($output, 'SiteConfig.SchemaOrganisationImageID -> SeoLogoID:', ['would copy 1']);
        $this->assertReportLine($output, 'SiteConfig.SchemaOrganisationName:', ['no equivalent', '1 values']);
        $this->assertReportLine($output, 'Priority [draft]:', ['no equivalent', '1 values']);
        $this->assertReportLine($output, 'ManualSchema [draft]:', ['no equivalent', '0 values']);
        $this->assertReportLine($output, 'SeoHeadTag:', ['no equivalent', '1 values', 'ExtraMeta']);

        $this->assertEmpty($this->raw('SiteTree', 'MetaTitle', $copied->ID));
        $this->assertSame(0, (int) $this->raw('SiteTree', 'MetaRobotsNoIndex', $copied->ID));
        $this->assertSame(0, (int) $this->raw('SiteConfig', 'SeoDefaultImageID', $this->siteConfigID()));
    }

    public function testApplyCopiesKeepsFilledTargetsAndIsIdempotent(): void
    {
        [$copied, $kept, $invalid, $imageID] = $this->seed();

        $this->runMigration('plasticstudio', ['--apply' => true]);

        $this->assertSame('All our courses', $this->raw('SiteTree', 'MetaTitle', $copied->ID));
        $this->assertSame('All our courses', $this->raw('SiteTree_Live', 'MetaTitle', $copied->ID));
        $this->assertSame('https://www.example.com/courses', $this->raw('SiteTree', 'CanonicalURL', $copied->ID));
        $this->assertSame(1, (int) $this->raw('SiteTree', 'MetaRobotsNoIndex', $copied->ID));
        $this->assertSame(1, (int) $this->raw('SiteTree', 'MetaRobotsNoFollow', $copied->ID));
        $this->assertSame(0, (int) $this->raw('SiteTree_Live', 'MetaRobotsNoIndex', $copied->ID));
        $this->assertSame($imageID, (int) $this->raw('SiteTree', 'OGImageID', $copied->ID));
        $this->assertSame($imageID, (int) $this->raw('SiteTree_Live', 'OGImageID', $copied->ID));
        $this->assertSame('Kept title', $this->raw('SiteTree', 'MetaTitle', $kept->ID));
        $this->assertSame('Kept title', $this->raw('SiteTree_Live', 'MetaTitle', $kept->ID));
        $this->assertEmpty($this->raw('SiteTree', 'CanonicalURL', $invalid->ID));
        # "none" is noindex plus nofollow
        $this->assertSame(1, (int) $this->raw('SiteTree', 'MetaRobotsNoIndex', $invalid->ID));
        $this->assertSame(1, (int) $this->raw('SiteTree', 'MetaRobotsNoFollow', $invalid->ID));
        $this->assertSame($imageID, (int) $this->raw('SiteConfig', 'SeoDefaultImageID', $this->siteConfigID()));
        $this->assertSame($imageID, (int) $this->raw('SiteConfig', 'SeoLogoID', $this->siteConfigID()));

        $again = $this->runMigration('plasticstudio', ['--apply' => true]);
        $this->assertReportLine($again, 'MetaTitle -> MetaTitle [draft]:', ['copied 0', 'already same 1', 'conflicts 1']);
        $this->assertReportLine($again, 'Robots -> MetaRobotsNoIndex [draft]:', ['copied 0', 'already same 3']);
        $this->assertReportLine($again, 'SiteConfig.DefaultSocialImageID -> SeoDefaultImageID:', ['copied 0', 'already same 1']);
    }

    public function testOverwriteReplacesConflictingValues(): void
    {
        [, $kept] = $this->seed();

        $output = $this->runMigration('plasticstudio', ['--apply' => true, '--overwrite' => true]);

        $this->assertReportLine($output, 'MetaTitle -> MetaTitle [draft]:', ['conflicts 1 (overwritten)']);
        $this->assertSame('Other title', $this->raw('SiteTree', 'MetaTitle', $kept->ID));
        $this->assertSame('Other title', $this->raw('SiteTree_Live', 'MetaTitle', $kept->ID));
    }
}
