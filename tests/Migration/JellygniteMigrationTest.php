<?php

namespace Restruct\Silverstripe\SEO\Tests\Migration;

/**
 * jellygnite/silverstripe-seo: page columns on the Page table; separate Facebook and Twitter texts and images,
 * of which the first one filled in becomes the sharing description or image here.
 */
class JellygniteMigrationTest extends SeoMigrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->addOldStagedColumn('Page', 'MetaTitle', 'varchar(255)');
        $this->addOldStagedColumn('Page', 'CanonicalURL', 'varchar(255)');
        $this->addOldStagedColumn('Page', 'FacebookPageTitle', 'varchar(255)');
        $this->addOldStagedColumn('Page', 'FacebookPageDescription', 'mediumtext');
        $this->addOldStagedColumn('Page', 'TwitterPageDescription', 'mediumtext');
        $this->addOldStagedColumn('Page', 'FacebookPageImageID', 'int NOT NULL DEFAULT 0');
        $this->addOldStagedColumn('Page', 'TwitterPageImageID', 'int NOT NULL DEFAULT 0');
        $this->addOldStagedColumn('Page', 'FocusKeyword', 'varchar(50)');
    }

    public function testDryRunThenApplyThenIdempotent(): void
    {
        $facebook = $this->createTestImage('facebook.png');
        $twitter = $this->createTestImage('twitter.png');

        $both = $this->publishedPage('Both');
        $twitterOnly = $this->publishedPage('Twitter only');
        foreach (['Page', 'Page_Live'] as $table) {
            $this->setRaw($table, $both->ID, [
                'MetaTitle' => 'Both texts',
                'CanonicalURL' => '/master/',
                'FacebookPageDescription' => 'From Facebook',
                'TwitterPageDescription' => 'From Twitter',
                'FacebookPageImageID' => $facebook->ID,
                'TwitterPageImageID' => $twitter->ID,
                'FacebookPageTitle' => 'Shared title',
                'FocusKeyword' => 'courses',
            ]);
            $this->setRaw($table, $twitterOnly->ID, [
                'TwitterPageDescription' => 'Only Twitter',
                'TwitterPageImageID' => $twitter->ID,
            ]);
        }

        $output = $this->runMigration('jellygnite');

        $this->assertStringContainsString('Dry run', $output);
        $this->assertReportLine($output, 'MetaTitle -> MetaTitle [draft]:', ['read 1', 'would copy 1']);
        $this->assertReportLine($output, 'CanonicalURL -> CanonicalURL [live]:', ['read 1', 'would copy 1']);
        $this->assertReportLine(
            $output,
            'FacebookPageDescription, TwitterPageDescription -> SEOFBdescription [draft]:',
            ['read 2', 'would copy 2']
        );
        $this->assertReportLine($output, 'FacebookPageImageID, TwitterPageImageID -> OGImageID [draft]:', ['would copy 2']);
        $this->assertReportLine($output, 'FacebookPageTitle [draft]:', ['no equivalent', '1 values']);
        $this->assertReportLine($output, 'FocusKeyword [draft]:', ['dropped', '1 values']);
        $this->assertEmpty($this->raw('SiteTree', 'SEOFBdescription', $both->ID));

        $this->runMigration('jellygnite', ['--apply' => true]);

        $this->assertSame('Both texts', $this->raw('SiteTree_Live', 'MetaTitle', $both->ID));
        $this->assertSame('/master/', $this->raw('SiteTree', 'CanonicalURL', $both->ID));
        $this->assertSame('From Facebook', $this->raw('SiteTree', 'SEOFBdescription', $both->ID));
        $this->assertSame('Only Twitter', $this->raw('SiteTree_Live', 'SEOFBdescription', $twitterOnly->ID));
        $this->assertSame($facebook->ID, (int) $this->raw('SiteTree', 'OGImageID', $both->ID));
        $this->assertSame($twitter->ID, (int) $this->raw('SiteTree_Live', 'OGImageID', $twitterOnly->ID));

        $again = $this->runMigration('jellygnite', ['--apply' => true]);
        $this->assertReportLine(
            $again,
            'FacebookPageDescription, TwitterPageDescription -> SEOFBdescription [live]:',
            ['copied 0', 'already same 2']
        );
    }
}
