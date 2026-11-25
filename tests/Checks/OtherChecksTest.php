<?php

namespace Restruct\Silverstripe\SEO\Tests\Checks;

use Restruct\Silverstripe\SEO\Checks\Rules\UrlSegmentCheck;
use Restruct\Silverstripe\SEO\Tests\SeoTestImages;
use SilverStripe\Assets\Dev\TestAssetStore;
use SilverStripe\ORM\DB;

/**
 * Checks 20 (structured data), 21 (sharing image) and 23 (URL segment).
 */
class OtherChecksTest extends ChecksTestCase
{
    use SeoTestImages;

    protected function setUp(): void
    {
        parent::setUp();
        TestAssetStore::activate('SeoOtherChecksTest');
    }

    protected function tearDown(): void
    {
        TestAssetStore::reset();
        parent::tearDown();
    }

    public function testStructuredDataPassesForTheModuleOutput(): void
    {
        $this->assertCheckPasses($this->page(), 'structured-data');
    }

    public function testStructuredDataFailsForInvalidJsonInTheHead(): void
    {
        # As a template or another module would print it (ExtraMeta only lets meta and link tags through)
        $page = $this->page(['ExtraHead' => '<script type="application/ld+json">{"@type": "Thing",}</script>']);
        $this->assertCheckFails($page, 'structured-data', 'not valid JSON');
    }

    public function testStructuredDataFailsForAMissingRequiredProperty(): void
    {
        # An Event without its required start date: the registry warns, the node is left out
        $this->assertCheckFails($this->page(['IncompleteEvent' => true]), 'structured-data', 'startDate');
    }

    public function testOgImagePassesWithASharingImage(): void
    {
        $image = $this->createTestImage('share.png');
        $this->assertCheckPasses($this->page(['OGImageID' => $image->ID]), 'og-image');
    }

    public function testOgImageFailsWithoutOne(): void
    {
        $this->assertCheckFails($this->page(), 'og-image');
    }

    public function testUrlSegmentPassesForAShortHyphenatedSegment(): void
    {
        $this->assertCheckPasses($this->page(['URLSegment' => 'pottery-courses']), 'url-segment');
    }

    public function testUrlSegmentFailsForUnderscoresLengthAndStopWords(): void
    {
        # Straight to the table: core's URL segment filter turns underscores into hyphens on write, so an
        # underscore comes from imported or migrated content
        $underscored = $this->page(['URLSegment' => 'pottery-courses']);
        DB::prepared_query('UPDATE "SiteTree" SET "URLSegment" = ? WHERE "ID" = ?', ['pottery_courses', $underscored->ID]);
        $this->assertCheckFails($underscored, 'url-segment', '_');
        $this->assertCheckFails(
            $this->page(['URLSegment' => str_repeat('pottery-', 12) . 'courses']),
            'url-segment'
        );
        $this->assertCheckFails(
            $this->page(['URLSegment' => 'the-best-of-the-courses-in-the-city']),
            'url-segment',
            'the'
        );
    }

    public function testUrlSegmentMaxLengthIsConfigurable(): void
    {
        UrlSegmentCheck::config()->set('max_length', 10);
        $this->assertCheckFails($this->page(['URLSegment' => 'pottery-courses']), 'url-segment');
    }
}
