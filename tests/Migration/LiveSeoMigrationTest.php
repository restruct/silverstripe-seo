<?php

namespace Restruct\Silverstripe\SEO\Tests\Migration;

use SilverStripe\ORM\DB;

/**
 * The older liveseo module: most page columns are this module's own, so they need no copy; StructuredData rows
 * are reported, and the one site organisation's logo and profiles are copied to SiteConfig.
 */
class LiveSeoMigrationTest extends SeoMigrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        # The page columns the older module had and this one has not
        $this->addOldStagedColumn('SiteTree', 'MetaKeywords', 'varchar(255)');
        $this->addOldStagedColumn('SiteTree', 'SEOPageSubject', 'varchar(255)');
        $this->addOldStagedColumn('SiteTree', 'SEOPageScore', 'int NOT NULL DEFAULT 0');
        $this->addOldStagedColumn('SiteTree', 'SEOFBPublisherlink', 'varchar(255)');
        $this->addOldStagedColumn('SiteTree', 'SEOFBAuthorlink', 'varchar(255)');
        $this->addOldStagedColumn('SiteTree', 'SEOGplusAuthorlink', 'varchar(255)');
        $this->addOldStagedColumn('SiteTree', 'SEOGplusPublisherlink', 'varchar(255)');
        $this->addOldColumn('SiteConfig', 'SEOTitleTemplate', 'varchar(255)');
    }

    /**
     * The StructuredData tables as the older module leaves them behind.
     */
    private function createStructuredDataTables(): void
    {
        $this->createOldTable('StructuredDataThing', [
            'ClassName' => 'varchar(255)',
            'DataItemHandle' => 'varchar(255)',
            'name' => 'varchar(255)',
            'identifier' => 'varchar(1000)',
            'description' => 'mediumtext',
            'url' => 'varchar(1000)',
            'sameAs' => 'mediumtext',
        ]);
        $this->createOldTable('StructuredDataOrganization', [
            'telephone' => 'varchar(255)',
            'faxNumber' => 'varchar(255)',
            'email' => 'varchar(100)',
            'aggregateRatingID' => 'int NOT NULL DEFAULT 0',
        ]);
        $this->createOldTable('StructuredDataOrganization_logo', [
            'StructuredDataOrganizationID' => 'int NOT NULL DEFAULT 0',
            'ImageID' => 'int NOT NULL DEFAULT 0',
        ]);
        $this->createOldTable('StructuredDataOrganization_address', [
            'StructuredDataOrganizationID' => 'int NOT NULL DEFAULT 0',
            'StructuredDataPostalAddressID' => 'int NOT NULL DEFAULT 0',
        ]);
        $this->createOldTable('StructuredDataOrganization_contactPoint', [
            'StructuredDataOrganizationID' => 'int NOT NULL DEFAULT 0',
            'StructuredDataContactPointID' => 'int NOT NULL DEFAULT 0',
        ]);
        $this->createOldTable('StructuredDataOrganization_location', [
            'StructuredDataOrganizationID' => 'int NOT NULL DEFAULT 0',
            'ChildID' => 'int NOT NULL DEFAULT 0',
        ]);
        $this->createOldTable('StructuredDataOrganization_department', [
            'StructuredDataOrganizationID' => 'int NOT NULL DEFAULT 0',
            'ChildID' => 'int NOT NULL DEFAULT 0',
        ]);
        $this->createOldTable('SiteTree_StructuredDataItems', [
            'SiteTreeID' => 'int NOT NULL DEFAULT 0',
            'StructuredDataThingID' => 'int NOT NULL DEFAULT 0',
        ]);
    }

    /**
     * One organisation with a branch (a location), an address, a contact point and a rating.
     */
    private function seedOrganisation(int $logoID): void
    {
        $this->createStructuredDataTables();
        $this->insertRaw('StructuredDataThing', [
            'ID' => 1,
            'ClassName' => 'StructuredDataOrganization',
            'DataItemHandle' => 'Main',
            'name' => 'Example Organisation',
            # A MultiValueField column: a serialised PHP array
            'sameAs' => serialize(['https://social.example.com/org', 'https://video.example.com/org']),
        ]);
        $this->insertRaw('StructuredDataThing', [
            'ID' => 2, 'ClassName' => 'StructuredDataLocalBusiness', 'name' => 'Branch',
        ]);
        $this->insertRaw('StructuredDataThing', ['ID' => 3, 'ClassName' => 'StructuredDataPostalAddress']);
        $this->insertRaw('StructuredDataThing', ['ID' => 4, 'ClassName' => 'StructuredDataContactPoint']);
        $this->insertRaw('StructuredDataThing', ['ID' => 5, 'ClassName' => 'StructuredDataAggregateRating']);
        $this->insertRaw('StructuredDataOrganization', [
            'ID' => 1, 'telephone' => '+31 20 000 0000', 'email' => 'info@example.com',
        ]);
        $this->insertRaw('StructuredDataOrganization', ['ID' => 2]);
        $this->insertRaw('StructuredDataOrganization_logo', ['StructuredDataOrganizationID' => 1, 'ImageID' => $logoID]);
        $this->insertRaw('StructuredDataOrganization_address', [
            'StructuredDataOrganizationID' => 1, 'StructuredDataPostalAddressID' => 3,
        ]);
        $this->insertRaw('StructuredDataOrganization_contactPoint', [
            'StructuredDataOrganizationID' => 1, 'StructuredDataContactPointID' => 4,
        ]);
        # The branch is a location of the main organisation, so it is not a second site organisation
        $this->insertRaw('StructuredDataOrganization_location', ['StructuredDataOrganizationID' => 1, 'ChildID' => 2]);
        $page = $this->publishedPage('Home');
        $this->insertRaw('SiteTree_StructuredDataItems', ['SiteTreeID' => $page->ID, 'StructuredDataThingID' => 1]);
    }

    public function testSharedColumnsNeedNoCopy(): void
    {
        $page = $this->publishedPage('About', [
            'MetaTitle' => 'About us, the long version',
            'MetaRobotsNoIndex' => true,
            'SEOFBdescription' => 'Shared description',
        ]);
        $page->publishSingle();

        $output = $this->runMigration('liveseo', ['--apply' => true]);

        $this->assertReportLine($output, 'MetaTitle:', ['same column', 'nothing to copy']);
        $this->assertReportLine($output, 'MetaRobotsNoIndex:', ['same column', 'nothing to copy']);
        $this->assertReportLine($output, 'MetaRobotsNoFollow:', ['same column']);
        $this->assertReportLine($output, 'MetaRobotsNoSnippet:', ['same column']);
        $this->assertReportLine($output, 'MetaRobotsNoCache:', ['same column']);
        $this->assertReportLine($output, 'SEOFBdescription:', ['same column', 'nothing to copy']);
        $this->assertSame('About us, the long version', $this->raw('SiteTree_Live', 'MetaTitle', $page->ID));
    }

    public function testDeadColumnsAreReportedAsDropped(): void
    {
        $page = $this->publishedPage('About');
        $this->setRaw('SiteTree', $page->ID, ['MetaKeywords' => 'one, two', 'SEOGplusAuthorlink' => 'https://plus.example.com/x']);
        $this->setRaw('SiteTree_Live', $page->ID, ['MetaKeywords' => 'one, two']);
        $this->setRaw('SiteConfig', $this->siteConfigID(), ['SEOTitleTemplate' => '$Title | $SiteConfig.Title']);

        $output = $this->runMigration('liveseo');

        $this->assertReportLine($output, 'MetaKeywords [draft]:', ['dropped', '1 values']);
        $this->assertReportLine($output, 'MetaKeywords [live]:', ['dropped', '1 values']);
        $this->assertReportLine($output, 'SEOGplusAuthorlink [draft]:', ['dropped', '1 values']);
        $this->assertReportLine($output, 'SEOPageSubject [draft]:', ['dropped', '0 values']);
        $this->assertReportLine($output, 'SiteConfig.SEOTitleTemplate:', ['dropped', '1 values']);
        # Left in place: the migration never drops or empties an old column
        $this->assertSame('one, two', $this->raw('SiteTree', 'MetaKeywords', $page->ID));
    }

    public function testStructuredDataDryRunReportsAndWritesNothing(): void
    {
        $logo = $this->createTestImage('logo.png', 400, 400);
        $this->seedOrganisation($logo->ID);

        $output = $this->runMigration('liveseo');

        $this->assertStringContainsString('Dry run', $output);
        $this->assertReportLine($output, 'StructuredData:', [
            '5 rows', 'Organization 1', 'LocalBusiness 1', 'PostalAddress 1', 'ContactPoint 1', 'AggregateRating 1',
        ]);
        $this->assertReportLine($output, 'StructuredData attached to 1 pages');
        $this->assertReportLine($output, 'Organization logo -> SiteConfig.SeoLogoID:', ['read 1', 'would copy 1']);
        $this->assertReportLine($output, 'Organization sameAs -> SiteConfig.SeoSameAs:', ['read 1', 'would copy 1']);
        $this->assertReportLine($output, 'Note: Organization name "Example Organisation"', ['SiteConfig.Title']);
        $this->assertReportLine($output, 'Organization telephone:', ['no equivalent', '1 values', 'schema_map']);
        $this->assertReportLine($output, 'Organization email:', ['no equivalent', '1 values']);
        $this->assertReportLine($output, 'Organization address:', ['no equivalent', '1 values']);
        $this->assertReportLine($output, 'Organization contactPoint:', ['no equivalent', '1 values']);
        $this->assertReportLine($output, 'Organization location:', ['no equivalent', '1 values']);
        $this->assertReportLine($output, 'AggregateRating:', ['dropped', '1 values']);
        $this->assertReportLine($output, 'Warning:', ['hard-coded', 'template']);

        $this->assertSame(0, (int) $this->raw('SiteConfig', 'SeoLogoID', $this->siteConfigID()));
        $this->assertEmpty($this->raw('SiteConfig', 'SeoSameAs', $this->siteConfigID()));
    }

    public function testStructuredDataApplyCopiesLogoAndProfilesOnce(): void
    {
        $logo = $this->createTestImage('logo.png', 400, 400);
        $this->seedOrganisation($logo->ID);

        $output = $this->runMigration('liveseo', ['--apply' => true]);

        $this->assertReportLine($output, 'Organization logo -> SiteConfig.SeoLogoID:', ['read 1', 'copied 1']);
        $this->assertSame($logo->ID, (int) $this->raw('SiteConfig', 'SeoLogoID', $this->siteConfigID()));
        $this->assertSame(
            "https://social.example.com/org\nhttps://video.example.com/org",
            $this->raw('SiteConfig', 'SeoSameAs', $this->siteConfigID())
        );

        # Idempotent: a second run finds the values in place
        $again = $this->runMigration('liveseo', ['--apply' => true]);
        $this->assertReportLine($again, 'Organization logo -> SiteConfig.SeoLogoID:', ['copied 0', 'already same 1']);
        $this->assertReportLine($again, 'Organization sameAs -> SiteConfig.SeoSameAs:', ['copied 0', 'already same 1']);
    }

    public function testSameAsStoredAsJsonIsReadToo(): void
    {
        $logo = $this->createTestImage('logo.png', 400, 400);
        $this->seedOrganisation($logo->ID);
        # Newer MultiValueField versions store JSON
        DB::prepared_query(
            'UPDATE "StructuredDataThing" SET "sameAs" = ? WHERE "ID" = 1',
            [json_encode(['https://social.example.com/org'])]
        );

        $this->runMigration('liveseo', ['--apply' => true]);

        $this->assertSame('https://social.example.com/org', $this->raw('SiteConfig', 'SeoSameAs', $this->siteConfigID()));
    }

    public function testFilledSiteConfigIsKeptUnlessOverwrite(): void
    {
        $logo = $this->createTestImage('logo.png', 400, 400);
        $other = $this->createTestImage('other.png', 400, 400);
        $this->seedOrganisation($logo->ID);
        $this->setRaw('SiteConfig', $this->siteConfigID(), ['SeoLogoID' => $other->ID, 'SeoSameAs' => 'https://kept.example.com']);

        $output = $this->runMigration('liveseo', ['--apply' => true]);

        $this->assertReportLine($output, 'Organization logo -> SiteConfig.SeoLogoID:', ['copied 0', 'conflicts 1 (kept)']);
        $this->assertSame($other->ID, (int) $this->raw('SiteConfig', 'SeoLogoID', $this->siteConfigID()));
        $this->assertSame('https://kept.example.com', $this->raw('SiteConfig', 'SeoSameAs', $this->siteConfigID()));

        $output = $this->runMigration('liveseo', ['--apply' => true, '--overwrite' => true]);

        $this->assertReportLine($output, 'Organization logo -> SiteConfig.SeoLogoID:', ['conflicts 1 (overwritten)']);
        $this->assertSame($logo->ID, (int) $this->raw('SiteConfig', 'SeoLogoID', $this->siteConfigID()));
    }

    public function testTwoSiteOrganisationsAreAConflictNotAGuess(): void
    {
        $logo = $this->createTestImage('logo.png', 400, 400);
        $this->seedOrganisation($logo->ID);
        $this->insertRaw('StructuredDataThing', ['ID' => 6, 'ClassName' => 'StructuredDataOrganization', 'name' => 'Second']);
        $this->insertRaw('StructuredDataOrganization', ['ID' => 6]);

        $output = $this->runMigration('liveseo', ['--apply' => true]);

        $this->assertReportLine($output, 'Warning:', ['2 top-level organisations', 'Example Organisation', 'Second']);
        $this->assertSame(0, (int) $this->raw('SiteConfig', 'SeoLogoID', $this->siteConfigID()));
    }

    public function testWithoutStructuredDataTablesThereIsANotice(): void
    {
        $output = $this->runMigration('liveseo');

        $this->assertReportLine($output, 'Note:', ['StructuredData', 'not found']);
    }
}
