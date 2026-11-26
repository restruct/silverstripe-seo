<?php

namespace Restruct\Silverstripe\SEO\Tests;

use Restruct\Silverstripe\SEO\Tests\Stub\SeoHeaderTestPage;
use Restruct\Silverstripe\SEO\Tests\Stub\SeoHeaderTestPageController;
use Restruct\Silverstripe\SEO\Tests\Stub\SeoTestPage;
use SilverStripe\Control\Director;
use SilverStripe\Dev\FunctionalTest;
use SilverStripe\SiteConfig\SiteConfig;
use SilverStripe\View\SSViewer;

/**
 * An X-Robots-Tag or Link header that the project's controller code sets (in init, before or after the parent's
 * init, or in an action) is the project's decision: the module never overwrites it, and never puts back one the
 * project removed.
 */
class SeoProjectHeadersTest extends FunctionalTest
{
    protected $usesDatabase = true;

    protected static $use_draft_site = false;

    protected static $extra_dataobjects = [
        SeoTestPage::class,
        SeoHeaderTestPage::class,
    ];

    private const ROBOTS = 'noindex, noimageindex';

    private const LINK = '<https://other.example/feed>; rel="alternate"';

    protected function setUp(): void
    {
        parent::setUp();
        SSViewer::set_themes(['restruct/silverstripe-seo:/tests/themes/seotest', '$default']);
        SeoHeaderTestPageController::$beforeParentInit = [];
        SeoHeaderTestPageController::$afterParentInit = [];
        SeoHeaderTestPageController::$inAction = [];
        $config = SiteConfig::current_site_config();
        $config->Title = 'Example Site';
        $config->write();

        # The page itself would send both headers: noindex, and its canonical
        $page = SeoHeaderTestPage::create(['Title' => 'Feed', 'URLSegment' => 'feed', 'MetaRobotsNoIndex' => true]);
        $page->write();
        $page->publishRecursive();
    }

    public function testTheModulesOwnHeadersWithoutProjectCode(): void
    {
        # Control: the page does get both headers when the project sets none
        $response = $this->get('feed');
        $this->assertSame('noindex', $response->getHeader('X-Robots-Tag'));
        $this->assertSame('<' . Director::absoluteURL('/feed') . '>; rel="canonical"', $response->getHeader('Link'));
    }

    public function testHeadersSetInInitBeforeTheParentAreKept(): void
    {
        SeoHeaderTestPageController::$beforeParentInit = ['X-Robots-Tag' => self::ROBOTS, 'Link' => self::LINK];
        $response = $this->get('feed');

        $this->assertSame(self::ROBOTS, $response->getHeader('X-Robots-Tag'));
        $this->assertSame(self::LINK, $response->getHeader('Link'));
    }

    public function testHeadersSetInInitAfterTheParentAreKept(): void
    {
        SeoHeaderTestPageController::$afterParentInit = ['X-Robots-Tag' => self::ROBOTS, 'Link' => self::LINK];
        $response = $this->get('feed');

        $this->assertSame(self::ROBOTS, $response->getHeader('X-Robots-Tag'));
        $this->assertSame(self::LINK, $response->getHeader('Link'));
    }

    public function testHeadersSetInAnActionAreKept(): void
    {
        SeoHeaderTestPageController::$inAction = ['X-Robots-Tag' => self::ROBOTS, 'Link' => self::LINK];
        $response = $this->get('feed/own');

        $this->assertSame(self::ROBOTS, $response->getHeader('X-Robots-Tag'));
        $this->assertSame(self::LINK, $response->getHeader('Link'));
    }

    public function testHeadersOnAResponseOfTheActionsOwnAreKept(): void
    {
        SeoHeaderTestPageController::$inAction = ['X-Robots-Tag' => self::ROBOTS, 'Link' => self::LINK];
        $response = $this->get('feed/ownresponse');

        $this->assertSame('feed body', (string) $response->getBody());
        $this->assertSame(self::ROBOTS, $response->getHeader('X-Robots-Tag'));
        $this->assertSame(self::LINK, $response->getHeader('Link'));
    }

    public function testOneHeaderSetByTheProjectLeavesTheOtherToTheModule(): void
    {
        SeoHeaderTestPageController::$inAction = ['X-Robots-Tag' => self::ROBOTS];
        $response = $this->get('feed/own');

        $this->assertSame(self::ROBOTS, $response->getHeader('X-Robots-Tag'));
        $this->assertSame('<' . Director::absoluteURL('/feed/own') . '>; rel="canonical"', $response->getHeader('Link'));
    }

    public function testAHeaderTheActionRemovedIsNotPutBack(): void
    {
        SeoHeaderTestPageController::$inAction = ['X-Robots-Tag' => '', 'Link' => ''];
        $response = $this->get('feed/removed');

        $this->assertNull($response->getHeader('X-Robots-Tag'));
        $this->assertNull($response->getHeader('Link'));
    }
}
