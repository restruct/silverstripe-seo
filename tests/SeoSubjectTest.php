<?php

namespace Restruct\Silverstripe\SEO\Tests;

use Restruct\Silverstripe\SEO\Tests\Stub\SeoFallbackTestExtension;
use Restruct\Silverstripe\SEO\Tests\Stub\SeoTestItem;
use Restruct\Silverstripe\SEO\Tests\Stub\SeoTestPage;
use Restruct\Silverstripe\SEO\Tests\Stub\SeoTestSubjectItem;
use SilverStripe\Assets\Dev\TestAssetStore;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\CMS\Model\VirtualPage;
use SilverStripe\Control\Director;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Dev\FunctionalTest;
use SilverStripe\SiteConfig\SiteConfig;
use SilverStripe\View\SSViewer;

/**
 * A holder page that shows a record on a sub-action (/holder/item/12): title, description, canonical, Open Graph
 * and robots come from that record once the controller declares it as the SEO subject. Also: a virtual page
 * points its canonical at the page it copies.
 */
class SeoSubjectTest extends FunctionalTest
{
    use SeoTestImages;

    protected $usesDatabase = true;

    protected static $use_draft_site = false;

    protected static $extra_dataobjects = [
        SeoTestPage::class,
        SeoTestItem::class,
        SeoTestSubjectItem::class,
    ];

    protected static $required_extensions = [
        SiteTree::class => [
            SeoFallbackTestExtension::class,
        ],
    ];

    private SeoTestPage $holder;

    protected function setUp(): void
    {
        parent::setUp();
        TestAssetStore::activate('SeoSubjectTest');
        SeoFallbackTestExtension::$image = null;
        SSViewer::set_themes(['restruct/silverstripe-seo:/tests/themes/seotest', '$default']);

        $config = SiteConfig::current_site_config();
        $config->Title = 'Example Site';
        $config->write();

        $this->holder = SeoTestPage::create([
            'Title' => 'Courses',
            'URLSegment' => 'courses',
            'MetaDescription' => 'All our courses.',
            'Content' => '<p>Holder text.</p>',
        ]);
        $this->holder->write();
        $this->holder->publishRecursive();
    }

    protected function tearDown(): void
    {
        SeoFallbackTestExtension::$image = null;
        TestAssetStore::reset();
        parent::tearDown();
    }

    private function head(HTTPResponse $response): string
    {
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        preg_match('#<head>(.*)</head>#s', (string) $response->getBody(), $m);
        $this->assertNotEmpty($m, 'response has a head');
        return $m[1];
    }

    private function item(array $data = []): SeoTestItem
    {
        $item = SeoTestItem::create(array_merge([
            'Title' => 'Welding basics',
            'HolderID' => $this->holder->ID,
            'Content' => '<p>Learn to weld in three days.</p>',
        ], $data));
        $item->write();
        return $item;
    }

    public function testDuckTypedRecordProvidesTitleDescriptionAndOpenGraph(): void
    {
        $item = $this->item(['MetaTitle' => 'Welding course']);
        $head = $this->head($this->get('courses/item/' . $item->ID));

        $this->assertStringContainsString('<title>Welding course | Example Site</title>', $head);
        # The record's Content gives the description fallback, not the holder's typed description
        $this->assertStringContainsString('<meta name="description" content="Learn to weld in three days.">', $head);
        $this->assertStringNotContainsString('All our courses.', $head);
        $this->assertStringContainsString('<meta property="og:title" content="Welding course">', $head);
        $this->assertStringContainsString('<meta property="og:description" content="Learn to weld in three days.">', $head);
    }

    public function testRecordMetaDescriptionWins(): void
    {
        $item = $this->item(['MetaDescription' => 'Typed for the record.']);
        $head = $this->head($this->get('courses/item/' . $item->ID));
        $this->assertStringContainsString('<meta name="description" content="Typed for the record.">', $head);
    }

    public function testCanonicalIsTheRecordLinkWithoutTrackingParameters(): void
    {
        $item = $this->item(['LinkQuery' => 'variant=b&utm_source=mail&utm_campaign=x&gclid=1']);
        $response = $this->get('courses/item/' . $item->ID . '?utm_medium=social');
        $head = $this->head($response);

        # The record's own link decides; a parameter it carries itself stays, tracking parameters go
        $expected = Director::absoluteURL('/courses/item/' . $item->ID) . '?variant=b';
        $this->assertStringContainsString('<link rel="canonical" href="' . htmlspecialchars($expected) . '">', $head);
        $this->assertStringContainsString('<meta property="og:url" content="' . htmlspecialchars($expected) . '">', $head);
        $this->assertSame('<' . $expected . '>; rel="canonical"', $response->getHeader('Link'));
    }

    public function testRecordImageIsTheSharingImage(): void
    {
        $image = $this->createTestImage('welding.png');
        $item = $this->item(['OGImageID' => $image->ID]);
        $head = $this->head($this->get('courses/item/' . $item->ID));
        $this->assertMatchesRegularExpression('#<meta property="og:image" content="[^"]*welding[^"]*">#', $head);
    }

    public function testRecordWithoutImageFallsBackToTheSiteDefault(): void
    {
        $default = $this->createTestImage('default.png');
        $config = SiteConfig::current_site_config();
        $config->SeoDefaultImageID = $default->ID;
        $config->write();

        $item = $this->item();
        $head = $this->head($this->get('courses/item/' . $item->ID));
        $this->assertMatchesRegularExpression('#<meta property="og:image" content="[^"]*default[^"]*">#', $head);
    }

    public function testRecordWithoutImageSkipsTheHolderImage(): void
    {
        # The holder's own image illustrates the listing, not the record shown on it: a record without an image
        # goes to the hook, then the site default
        $this->holder->OGImageID = $this->createTestImage('holder.png')->ID;
        $this->holder->write();
        $this->holder->publishRecursive();
        $default = $this->createTestImage('default.png');
        $config = SiteConfig::current_site_config();
        $config->SeoDefaultImageID = $default->ID;
        $config->write();

        $item = $this->item();
        $head = $this->head($this->get('courses/item/' . $item->ID));
        $this->assertStringNotContainsString('holder', $head);
        $this->assertMatchesRegularExpression('#<meta property="og:image" content="[^"]*default[^"]*">#', $head);

        SeoFallbackTestExtension::$image = $this->createTestImage('hook.png');
        $head = $this->head($this->get('courses/item/' . $item->ID));
        $this->assertStringNotContainsString('holder', $head);
        $this->assertMatchesRegularExpression('#<meta property="og:image" content="[^"]*hook[^"]*">#', $head);

        # The holder itself still uses its own image
        SeoFallbackTestExtension::$image = null;
        $this->assertMatchesRegularExpression(
            '#<meta property="og:image" content="[^"]*holder[^"]*">#',
            $this->head($this->get('courses'))
        );
    }

    public function testInterfaceRecordAnswersForItself(): void
    {
        $item = SeoTestSubjectItem::create(['Title' => 'Ignored', 'Hidden' => true]);
        $item->write();
        $response = $this->get('courses/subjectitem/' . $item->ID);
        $head = $this->head($response);

        $this->assertStringContainsString('<title>Interface title | Example Site</title>', $head);
        $this->assertStringContainsString('<meta name="description" content="Interface description.">', $head);
        $canonical = Director::absoluteURL('/elsewhere/' . $item->ID);
        $this->assertStringContainsString('<link rel="canonical" href="' . $canonical . '">', $head);
        $this->assertStringContainsString('<meta name="robots" content="noindex, nofollow">', $head);
        $this->assertSame('noindex, nofollow', $response->getHeader('X-Robots-Tag'));
    }

    public function testRecordRobotsReplaceThePageRobotsInHeaders(): void
    {
        # The holder is noindex (its listing is thin), the record it shows is meant to be found: the header set
        # during init, before the action declared its subject, must not survive
        $this->holder->MetaRobotsNoIndex = true;
        $this->holder->write();
        $this->holder->publishRecursive();

        $item = SeoTestSubjectItem::create(['Title' => 'Visible', 'Hidden' => false]);
        $item->write();
        $response = $this->get('courses/subjectitem/' . $item->ID);
        $head = $this->head($response);

        $this->assertStringNotContainsString('name="robots"', $head);
        $this->assertNull($response->getHeader('X-Robots-Tag'));
    }

    public function testDuckTypedRecordInheritsThePageRobots(): void
    {
        # A record without robots fields of its own follows the page it is shown on
        $this->holder->MetaRobotsNoIndex = true;
        $this->holder->write();
        $this->holder->publishRecursive();

        $item = $this->item();
        $response = $this->get('courses/item/' . $item->ID);
        $this->assertStringContainsString('<meta name="robots" content="noindex">', $this->head($response));
        $this->assertSame('noindex', $response->getHeader('X-Robots-Tag'));
    }

    public function testHolderItselfIsUnaffected(): void
    {
        $head = $this->head($this->get('courses'));
        $this->assertStringContainsString('<title>Courses | Example Site</title>', $head);
        $this->assertStringContainsString('<meta name="description" content="All our courses.">', $head);
    }

    public function testVirtualPageCanonicalPointsAtItsSource(): void
    {
        $virtual = VirtualPage::create(['Title' => 'Courses copy']);
        $virtual->CopyContentFromID = $this->holder->ID;
        $virtual->write();
        $virtual->publishRecursive();

        $this->assertSame(Director::absoluteURL('/courses'), $virtual->SeoCanonicalURL());

        # VirtualPage copies URLSegment from its source on first write (VirtualPage.initially_copied_fields), so
        # the requested URL comes from the record, not from the segment given above
        $response = $this->get($virtual->Link());
        $this->assertSame('<' . Director::absoluteURL('/courses') . '>; rel="canonical"', $response->getHeader('Link'));
    }
}
