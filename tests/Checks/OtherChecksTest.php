<?php

namespace Restruct\Silverstripe\SEO\Tests\Checks;

use Restruct\Silverstripe\SEO\Checks\Rules\UrlSegmentCheck;
use Restruct\Silverstripe\SEO\Tests\SeoTestImages;
use SilverStripe\Assets\Dev\TestAssetStore;

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
        $page = $this->page();
        $page->ExtraMeta = '<script type="application/ld+json">{"@type": "Thing",}</script>';
        $page->write();
        $this->assertCheckFails($page, 'structured-data');
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
        $this->assertCheckFails($this->page(['URLSegment' => 'pottery_courses']), 'url-segment', '_');
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
