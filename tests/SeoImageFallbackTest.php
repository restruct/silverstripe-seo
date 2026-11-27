<?php

namespace Restruct\Silverstripe\SEO\Tests;

use Restruct\Silverstripe\SEO\Checks\Rules\OgImageCheck;
use Restruct\Silverstripe\SEO\Tests\Stub\SeoFallbackTestExtension;
use Restruct\Silverstripe\SEO\Tests\Stub\SeoImageFallbackTestPage;
use SilverStripe\Assets\Dev\TestAssetStore;
use SilverStripe\Assets\File;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\SiteConfig\SiteConfig;

/**
 * `seo_image_fallback`: relations and methods of the page tried in order for a sharing image when the page has
 * none of its own, before the `updateSeoImageFallback` hook (which can still override it) and the site default.
 */
class SeoImageFallbackTest extends SapphireTest
{
    use SeoTestImages;

    protected $usesDatabase = true;

    protected static $extra_dataobjects = [
        SeoImageFallbackTestPage::class,
    ];

    protected static $required_extensions = [
        SiteTree::class => [
            SeoFallbackTestExtension::class,
        ],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        TestAssetStore::activate('SeoImageFallbackTest');
        SeoFallbackTestExtension::$image = null;
        SeoImageFallbackTestPage::$cardImage = null;
    }

    protected function tearDown(): void
    {
        SeoFallbackTestExtension::$image = null;
        SeoImageFallbackTestPage::$cardImage = null;
        TestAssetStore::reset();
        parent::tearDown();
    }

    private function page(array $data = []): SeoImageFallbackTestPage
    {
        $page = SeoImageFallbackTestPage::create(array_merge(['Title' => 'Course', 'URLSegment' => 'course'], $data));
        $page->write();
        return $page;
    }

    private function fallback(array $names): void
    {
        SeoImageFallbackTestPage::config()->set('seo_image_fallback', $names);
    }

    public function testEmptyByDefault(): void
    {
        $this->assertSame([], SiteTree::config()->get('seo_image_fallback'));
        $hero = $this->createTestImage('hero.png');
        $this->assertNull($this->page(['HeroImageID' => $hero->ID])->SeoImage(), 'nothing is tried unless configured');
    }

    public function testAHasOneImage(): void
    {
        $hero = $this->createTestImage('hero.png');
        $this->fallback(['HeroImage']);
        $this->assertSame($hero->ID, $this->page(['HeroImageID' => $hero->ID])->SeoImage()?->ID);
    }

    public function testTheFirstImageOfAManyMany(): void
    {
        $first = $this->createTestImage('first.png');
        $second = $this->createTestImage('second.png');
        $page = $this->page();
        $page->Gallery()->add($first);
        $page->Gallery()->add($second);
        $this->fallback(['Gallery']);
        $this->assertSame($first->ID, $page->SeoImage()?->ID);
    }

    public function testAMethodReturningAnImage(): void
    {
        SeoImageFallbackTestPage::$cardImage = $this->createTestImage('card.png');
        $this->fallback(['CardImage']);
        $this->assertSame(SeoImageFallbackTestPage::$cardImage->ID, $this->page()->SeoImage()?->ID);
    }

    public function testTriedInOrderSkippingEmptyMissingAndNonImageEntries(): void
    {
        $file = File::create();
        $file->setFromString('not an image', 'seo-tests/notes.txt');
        $file->write();
        $file->publishSingle();
        $gallery = $this->createTestImage('gallery.png');
        $page = $this->page(['AttachmentID' => $file->ID]);
        $page->Gallery()->add($gallery);
        # No such relation, a file that is no image, an empty has_one, a method returning null: all passed over
        $this->fallback(['NoSuchRelation', 'Attachment', 'HeroImage', 'CardImage', 'Gallery']);
        $this->assertSame($gallery->ID, $page->SeoImage()?->ID);
    }

    public function testThePagesOwnImageComesFirst(): void
    {
        $own = $this->createTestImage('own.png');
        $hero = $this->createTestImage('hero.png');
        $this->fallback(['HeroImage']);
        $this->assertSame($own->ID, $this->page(['OGImageID' => $own->ID, 'HeroImageID' => $hero->ID])->SeoImage()?->ID);
    }

    public function testTheHookStillWinsAndTheSiteDefaultComesLast(): void
    {
        $hero = $this->createTestImage('hero.png');
        $hook = $this->createTestImage('hook.png');
        $default = $this->createTestImage('default.png');
        $config = SiteConfig::current_site_config();
        $config->SeoDefaultImageID = $default->ID;
        $config->write();
        $this->fallback(['HeroImage']);

        SeoFallbackTestExtension::$image = $hook;
        $this->assertSame($hook->ID, $this->page(['HeroImageID' => $hero->ID])->SeoImage()?->ID, 'the hook overrides');
        SeoFallbackTestExtension::$image = null;
        $this->assertSame($hero->ID, $this->page(['HeroImageID' => $hero->ID, 'URLSegment' => 'b'])->SeoImage()?->ID, 'the config before the default');
        $this->assertSame($default->ID, $this->page(['URLSegment' => 'c'])->SeoImage()?->ID, 'the default when the config finds nothing');
    }

    public function testADraftOnlyImageIsSkipped(): void
    {
        $draft = $this->createTestImage('draft.png');
        $draft->doUnpublish();
        $card = $this->createTestImage('card.png');
        SeoImageFallbackTestPage::$cardImage = $card;
        $this->fallback(['HeroImage', 'CardImage']);
        $this->assertSame($card->ID, $this->page(['HeroImageID' => $draft->ID])->SeoImage()?->ID);
    }

    public function testTheOgImageAdviceMentionsTheSetting(): void
    {
        $this->assertStringContainsString('seo_image_fallback', OgImageCheck::singleton()->howToFix());
    }
}
