<?php

namespace Restruct\Silverstripe\SEO\Tests;

use Restruct\Silverstripe\SEO\Tests\Stub\SeoFallbackTestExtension;
use Restruct\Silverstripe\SEO\Tests\Stub\SeoTestPage;
use SilverStripe\Assets\Dev\TestAssetStore;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Control\Director;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Core\Kernel;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\SiteConfig\SiteConfig;
use SilverStripe\Versioned\Versioned;

/**
 * The values the page extension computes: title, description fallback, robots, canonical and Open Graph, each with
 * its fallback chain. What reaches the rendered head is covered separately in {@see SeoHeadOutputTest}.
 */
class SeoSiteTreeExtensionTest extends SapphireTest
{
    use SeoTestImages;

    protected $usesDatabase = true;

    protected static $extra_dataobjects = [
        SeoTestPage::class,
    ];

    protected static $required_extensions = [
        SiteTree::class => [
            SeoFallbackTestExtension::class,
        ],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        TestAssetStore::activate('SeoSiteTreeExtensionTest');
        SeoFallbackTestExtension::$image = null;
        SeoFallbackTestExtension::$description = null;

        $config = SiteConfig::current_site_config();
        $config->Title = 'Example Site';
        $config->write();
    }

    protected function tearDown(): void
    {
        SeoFallbackTestExtension::$image = null;
        SeoFallbackTestExtension::$description = null;
        TestAssetStore::reset();
        parent::tearDown();
    }

    private function page(array $data = []): SeoTestPage
    {
        $page = SeoTestPage::create(array_merge(['Title' => 'About us', 'URLSegment' => 'about-us'], $data));
        $page->write();
        return $page;
    }

    # --- Title -------------------------------------------------------------------------------------------------

    public function testTitleFallsBackToPageTitleAndAppendsTheSiteName(): void
    {
        $this->assertSame('About us | Example Site', $this->page()->SeoTitle());
    }

    public function testMetaTitleReplacesThePageTitle(): void
    {
        $page = $this->page(['MetaTitle' => 'Who we are']);
        $this->assertSame('Who we are | Example Site', $page->SeoTitle());
        # Open Graph carries the bare title: og:site_name already says which site it is
        $this->assertSame('Who we are', $page->SeoPageTitle());
    }

    public function testTitleSuffixFromSiteConfigOverridesTheSiteName(): void
    {
        $config = SiteConfig::current_site_config();
        $config->SeoTitleSuffix = 'Example';
        $config->write();

        $this->assertSame('About us | Example', $this->page()->SeoTitle());
    }

    public function testSeparatorIsConfigurable(): void
    {
        SiteTree::config()->set('seo_title_separator', ' - ');
        $this->assertSame('About us - Example Site', $this->page()->SeoTitle());
    }

    public function testSuffixCanBeSwitchedOff(): void
    {
        SiteTree::config()->set('seo_title_append_site_name', false);
        $this->assertSame('About us', $this->page()->SeoTitle());
    }

    public function testSiteNameIsNotRepeated(): void
    {
        # A title that already is, or already ends with, the site name would otherwise read "Example Site |
        # Example Site": Google lists a repeated site name as one of the reasons it rewrites a title
        $this->assertSame('Example Site', $this->page(['MetaTitle' => 'Example Site'])->SeoTitle());
        $this->assertSame(
            'Courses | Example Site',
            $this->page(['MetaTitle' => 'Courses | Example Site'])->SeoTitle()
        );
    }

    public function testSiteNameInsideAWordIsNotTakenForTheSuffix(): void
    {
        # "Workshop" ends with the letters of "Shop", but does not end with the site name
        $config = SiteConfig::current_site_config();
        $config->Title = 'Shop';
        $config->write();

        $this->assertSame('Workshop | Shop', $this->page(['MetaTitle' => 'Workshop'])->SeoTitle());
        # A title already ending in the site name after another separator is still left alone
        $this->assertSame('Courses - Shop', $this->page(['MetaTitle' => 'Courses - Shop'])->SeoTitle());
    }

    # --- Description -------------------------------------------------------------------------------------------

    public function testMetaDescriptionIsUsedAsIs(): void
    {
        $page = $this->page([
            'MetaDescription' => 'Written by an editor.',
            'Content' => '<p>Something else entirely.</p>',
        ]);
        $this->assertSame('Written by an editor.', $page->SeoDescription());
    }

    public function testDescriptionFallsBackToTheFirstParagraphOfContent(): void
    {
        $page = $this->page([
            'Content' => '<h2>Intro</h2><p>  </p><p>We build <strong>things</strong> &amp; fix them.</p><p>Second.</p>',
        ]);
        $this->assertSame('We build things & fix them.', $page->SeoDescription());
    }

    public function testDescriptionFallbackIsCutAtAWordBoundary(): void
    {
        $words = str_repeat('lorem ipsum dolor sit amet ', 20);
        $page = $this->page(['Content' => '<p>' . $words . '</p>']);
        $description = $page->SeoDescription();

        $this->assertLessThanOrEqual(155, mb_strlen($description));
        $this->assertGreaterThan(130, mb_strlen($description), 'the cut should not throw away more than one word');
        # Ends on a whole word from the source text, without a dangling space
        $this->assertStringStartsWith($description, trim($words));
        $this->assertStringEndsNotWith(' ', $description);
        $this->assertContains(substr($description, strrpos($description, ' ') + 1), ['lorem', 'ipsum', 'dolor', 'sit', 'amet']);
    }

    public function testDescriptionFallbackLengthIsConfigurable(): void
    {
        SiteTree::config()->set('seo_description_fallback_length', 20);
        $page = $this->page(['Content' => '<p>One two three four five six seven eight.</p>']);
        $this->assertSame('One two three four', $page->SeoDescription());
    }

    public function testDescriptionFallbackDropsShortcodes(): void
    {
        $page = $this->page([
            'Content' => '<p>[image src="x.jpg" id="3"]Read the <a href="[sitetree_link,id=1]">guide</a> first.</p>',
        ]);
        $this->assertSame('Read the guide first.', $page->SeoDescription());
    }

    public function testDescriptionFallbackUsesTextWhenThereIsNoParagraph(): void
    {
        $page = $this->page(['Content' => '<div>Plain block of text</div>']);
        $this->assertSame('Plain block of text', $page->SeoDescription());
    }

    public function testDescriptionFallbackHookCanReplaceTheFallback(): void
    {
        # The hook is where a later version, or a project, feeds in text from the rendered page (Elemental blocks)
        SeoFallbackTestExtension::$description = 'From the rendered page.';
        $page = $this->page(['Content' => '<p>From the Content field.</p>']);
        $this->assertSame('From the rendered page.', $page->SeoDescription());

        # ...but never overrides what an editor wrote
        $page->MetaDescription = 'Editor text.';
        $this->assertSame('Editor text.', $page->SeoDescription());
    }

    public function testDescriptionFallbackCanBeSwitchedOff(): void
    {
        SiteTree::config()->set('seo_description_fallback', false);
        $page = $this->page(['Content' => '<p>Some content.</p>']);
        $this->assertSame('', $page->SeoDescription());
    }

    # --- Robots ------------------------------------------------------------------------------------------------

    public function testRobotsIsEmptyByDefault(): void
    {
        # index, follow is what search engines assume; saying it out loud adds nothing
        $this->assertSame('', $this->page()->SeoRobots());
    }

    public function testRobotsFlagsMapToDirectives(): void
    {
        $page = $this->page([
            'MetaRobotsNoIndex' => true,
            'MetaRobotsNoFollow' => true,
            'MetaRobotsNoSnippet' => true,
            # The old column name says "cache"; the directive search engines understand for it is noarchive
            'MetaRobotsNoCache' => true,
        ]);
        $this->assertSame('noindex, nofollow, nosnippet, noarchive', $page->SeoRobots());
    }

    public function testSingleRobotsFlag(): void
    {
        $this->assertSame('nosnippet', $this->page(['MetaRobotsNoSnippet' => true])->SeoRobots());
    }

    public function testNonLiveEnvironmentCanBeNoindexed(): void
    {
        # Off by default: a test or dev site behind its own protection does not need it
        $this->assertSame('', $this->page()->SeoRobots());

        SiteTree::config()->set('seo_noindex_non_live', true);
        $this->assertFalse(Director::isLive(), 'the suite runs in a dev or test environment');
        $this->assertSame('noindex, nofollow', $this->page()->SeoRobots());
        # Combined with the page's own flags without repeating any
        $this->assertSame(
            'noindex, nofollow, nosnippet',
            $this->page(['MetaRobotsNoIndex' => true, 'MetaRobotsNoSnippet' => true])->SeoRobots()
        );

        # On live the setting does nothing
        $kernel = Injector::inst()->get(Kernel::class);
        $environment = $kernel->getEnvironment();
        try {
            $kernel->setEnvironment(Kernel::LIVE);
            $this->assertSame('', $this->page()->SeoRobots());
        } finally {
            $kernel->setEnvironment($environment);
        }
    }

    # --- Canonical ---------------------------------------------------------------------------------------------

    public function testCanonicalDefaultsToTheAbsolutePageUrl(): void
    {
        $page = $this->page();
        $this->assertSame(Director::absoluteURL('/about-us'), $page->SeoCanonicalURL());
    }

    public function testCanonicalHasNoStageParameterInDraft(): void
    {
        $page = $this->page();
        Versioned::withVersionedMode(function () use ($page) {
            Versioned::set_stage(Versioned::DRAFT);
            Versioned::set_default_reading_mode(Versioned::LIVE);
            $this->assertStringNotContainsString('stage=', $page->SeoCanonicalURL());
        });
    }

    public function testCanonicalOverrideAcceptsAnAbsoluteUrl(): void
    {
        $page = $this->page(['CanonicalURL' => 'https://other.example.org/original?x=1']);
        $this->assertSame('https://other.example.org/original?x=1', $page->SeoCanonicalURL());
    }

    public function testCanonicalOverrideAcceptsARootRelativePath(): void
    {
        $page = $this->page(['CanonicalURL' => '/the-original']);
        $this->assertSame(Director::absoluteURL('/the-original'), $page->SeoCanonicalURL());
    }

    public function testCanonicalForAnActionUrlIsThatUrl(): void
    {
        $page = $this->page();
        $request = new HTTPRequest('GET', 'about-us/detail/5', ['utm_source' => 'mail']);
        $request->setRouteParams(['Action' => 'detail', 'ID' => '5']);
        # Tracking parameters are dropped: they are the textbook case of a duplicate URL
        $this->assertSame(Director::absoluteURL('/about-us/detail/5'), $page->SeoCanonicalURL($request));
    }

    public function testCanonicalKeepsOnlyAllowedQueryParameters(): void
    {
        SiteTree::config()->set('seo_canonical_query_params', ['start']);
        $page = $this->page();
        $request = new HTTPRequest('GET', 'about-us', ['start' => '20', 'utm_source' => 'mail']);
        $this->assertSame(Director::absoluteURL('/about-us') . '?start=20', $page->SeoCanonicalURL($request));
    }

    public function testInvalidCanonicalOverrideIsRejected(): void
    {
        $page = $this->page();
        $page->CanonicalURL = 'not a url';
        $this->assertFalse($page->validate()->isValid());

        $page->CanonicalURL = 'javascript:alert(1)';
        $this->assertFalse($page->validate()->isValid());

        $page->CanonicalURL = '/fine';
        $this->assertTrue($page->validate()->isValid());
    }

    # --- Open Graph --------------------------------------------------------------------------------------------

    public function testOpenGraphDescriptionPrefersItsOwnField(): void
    {
        $page = $this->page(['SEOFBdescription' => 'For sharing.', 'MetaDescription' => 'For search.']);
        $this->assertSame('For sharing.', $page->SeoOGDescription());
    }

    public function testOpenGraphDescriptionFallsBackToTheMetaDescriptionChain(): void
    {
        $this->assertSame('For search.', $this->page(['MetaDescription' => 'For search.'])->SeoOGDescription());
        $this->assertSame('From content.', $this->page(['Content' => '<p>From content.</p>'])->SeoOGDescription());
    }

    public function testOpenGraphTypeIsConfigurablePerPageClass(): void
    {
        $this->assertSame('website', $this->page()->SeoOGType());
        SeoTestPage::config()->set('seo_og_type', 'article');
        $this->assertSame('article', $this->page()->SeoOGType());
    }

    public function testImageIsThePageImageFirst(): void
    {
        $own = $this->createTestImage('own.png');
        $hook = $this->createTestImage('hook.png');
        $default = $this->createTestImage('default.png');
        SeoFallbackTestExtension::$image = $hook;
        $this->setDefaultImage($default);

        $page = $this->page(['OGImageID' => $own->ID]);
        $this->assertSame($own->ID, $page->SeoImage()?->ID);
    }

    public function testImageFallsBackToTheHookThenTheSiteDefault(): void
    {
        $hook = $this->createTestImage('hook.png');
        $default = $this->createTestImage('default.png');
        $this->setDefaultImage($default);
        $page = $this->page();

        SeoFallbackTestExtension::$image = $hook;
        $this->assertSame($hook->ID, $page->SeoImage()?->ID);

        SeoFallbackTestExtension::$image = null;
        $this->assertSame($default->ID, $page->SeoImage()?->ID);
    }

    public function testImagesThatAreNotPublicAreSkipped(): void
    {
        # A crawler fetching og:image is anonymous: a draft-only image, or one only logged-in members may view,
        # sits in the protected store and would answer it with a 403 or 404
        $draft = $this->createTestImage('draft.png');
        $draft->doUnpublish();
        $restricted = $this->createTestImage('restricted.png');
        $restricted->CanViewType = 'LoggedInUsers';
        $restricted->write();
        $restricted->publishSingle();
        $default = $this->createTestImage('default.png');
        $this->setDefaultImage($default);

        $this->assertSame($default->ID, $this->page(['OGImageID' => $draft->ID])->SeoImage()?->ID, 'draft-only page image');
        $this->assertSame($default->ID, $this->page(['OGImageID' => $restricted->ID])->SeoImage()?->ID, 'restricted page image');

        SeoFallbackTestExtension::$image = $restricted;
        $this->assertSame($default->ID, $this->page()->SeoImage()?->ID, 'restricted hook image');

        # A site default that is not public either leaves no image at all
        SeoFallbackTestExtension::$image = null;
        $this->setDefaultImage($restricted);
        $this->assertNull($this->page()->SeoImage(), 'restricted default image');
    }

    public function testNoImageAtAllGivesNull(): void
    {
        $this->assertNull($this->page()->SeoImage());
    }

    private function setDefaultImage($image): void
    {
        $config = SiteConfig::current_site_config();
        $config->SeoDefaultImageID = $image->ID;
        $config->write();
    }
}
