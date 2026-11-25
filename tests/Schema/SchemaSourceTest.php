<?php

namespace Restruct\Silverstripe\SEO\Tests\Schema;

use Restruct\Silverstripe\SEO\Schema\PageSchema;
use Restruct\Silverstripe\SEO\Schema\SchemaContext;
use Restruct\Silverstripe\SEO\Schema\SchemaMapper;
use Restruct\Silverstripe\SEO\Tests\SeoTestImages;
use Restruct\Silverstripe\SEO\Tests\Stub\Schema\SchemaTestCategory;
use Restruct\Silverstripe\SEO\Tests\Stub\Schema\SchemaTestCoursePage;
use Restruct\Silverstripe\SEO\Tests\Stub\Schema\SchemaTestDataExtension;
use Restruct\Silverstripe\SEO\Tests\Stub\Schema\SchemaTestEvent;
use Restruct\Silverstripe\SEO\Tests\Stub\Schema\SchemaTestGetterItem;
use Restruct\Silverstripe\SEO\Tests\Stub\Schema\SchemaTestHolderPage;
use Restruct\Silverstripe\SEO\Tests\Stub\Schema\SchemaTestJobPage;
use Restruct\Silverstripe\SEO\Tests\Stub\Schema\SchemaTestPlace;
use Restruct\Silverstripe\SEO\Tests\Stub\Schema\SchemaTestTicket;
use Restruct\Silverstripe\SEO\Tests\Stub\SeoTestItem;
use Restruct\Silverstripe\SEO\Tests\Stub\SeoTestPage;
use SilverStripe\Assets\Dev\TestAssetStore;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Control\Director;
use SilverStripe\Core\Config\Config;
use SilverStripe\Dev\SapphireTest;

/**
 * Records as structured-data sources: the `schema_map` of the SchemaSource extension (fields, methods, relation
 * paths, literals, nested maps, references, has_many and many_many lists), `provideSchemaData()` for data that
 * has no fields (an API cache), the `updateSchemaData` hook, and the rule for stable `@id`s.
 */
class SchemaSourceTest extends SapphireTest
{
    use SeoTestImages;

    protected $usesDatabase = true;

    protected static $extra_dataobjects = [
        SeoTestPage::class,
        SeoTestItem::class,
        SchemaTestHolderPage::class,
        SchemaTestEvent::class,
        SchemaTestPlace::class,
        SchemaTestTicket::class,
        SchemaTestCategory::class,
        SchemaTestJobPage::class,
        SchemaTestCoursePage::class,
        SchemaTestGetterItem::class,
    ];

    protected static $required_extensions = [
        SchemaTestEvent::class => [
            SchemaTestDataExtension::class,
        ],
    ];

    private SchemaTestHolderPage $holder;

    protected function setUp(): void
    {
        parent::setUp();
        TestAssetStore::activate('SchemaSourceTest');
        SchemaTestDataExtension::$active = false;
        SiteTree::config()->set('seo_schema_timezone', 'Europe/Amsterdam');

        $this->holder = SchemaTestHolderPage::create(['Title' => 'Events', 'URLSegment' => 'events']);
        $this->holder->write();
    }

    protected function tearDown(): void
    {
        TestAssetStore::reset();
        parent::tearDown();
    }

    private function mapper(?SiteTree $page = null): SchemaMapper
    {
        return SchemaMapper::create(SchemaContext::create($page ?? $this->holder));
    }

    private function base(): string
    {
        return rtrim(Director::absoluteBaseURL(), '/');
    }

    private function event(array $data = []): SchemaTestEvent
    {
        $category = SchemaTestCategory::create(['Title' => 'Open days']);
        $category->write();
        $event = SchemaTestEvent::create(array_merge([
            'Title' => 'Open day',
            'Description' => '<p>Come <strong>and see</strong>.</p>',
            'StartDate' => '2027-03-13',
            'StartTime' => '10:00:00',
            'EndDate' => '2027-03-13',
            'EndTime' => '15:30:00',
            'HolderID' => $this->holder->ID,
            'CategoryID' => $category->ID,
        ], $data));
        $event->write();
        return $event;
    }

    private function place(string $title, string $city): SchemaTestPlace
    {
        $place = SchemaTestPlace::create([
            'Title' => $title,
            'Street' => 'Main street 1',
            'PostalCode' => '1234 AB',
            'City' => $city,
            'Country' => 'NL',
        ]);
        $place->write();
        return $place;
    }

    public function testFieldsMethodsPathsLiteralsDatesAndReferences(): void
    {
        $event = $this->event();
        $node = $this->mapper()->nodesFor($event)[0];
        $url = Director::absoluteURL('/events/event/' . $event->ID);

        $this->assertSame('Event', $node['@type']);
        $this->assertSame($url . '#event-' . $event->ID, $node['@id']);
        # A field; HTML becomes plain text
        $this->assertSame('Open day', $node['name']);
        $this->assertSame('Come and see.', $node['description']);
        # A method
        $this->assertSame($url, $node['url']);
        # Date and time fields combined, with the offset of the site timezone on that day (winter time)
        $this->assertSame('2027-03-13T10:00:00+01:00', $node['startDate']);
        $this->assertSame('2027-03-13T15:30:00+01:00', $node['endDate']);
        # A literal
        $this->assertSame('https://schema.org/EventScheduled', $node['eventStatus']);
        # A dotted relation path
        $this->assertSame('Open days', $node['keywords']);
        # A reference to the site's organisation node, by @id only
        $this->assertSame(['@id' => $this->base() . '/#organization'], $node['organizer']);
    }

    public function testGettersAndCastingAreHonoured(): void
    {
        $item = SchemaTestGetterItem::create(['Title' => 'Plain']);
        $item->write();
        $mapper = $this->mapper();

        # As a template reads it: the getter overrides the column
        $this->assertSame('Overridden Plain', $mapper->resolve($item, 'Title'));
        # A getter without a column
        $this->assertSame('Hello there', $mapper->resolve($item, 'Tagline'));
        # A method cast as HTMLText is HTML, so it becomes plain text with its entities decoded
        $this->assertSame('Fish & chips', $mapper->resolve($item, 'Teaser'));
    }

    public function testOnlyFieldsRelationsGettersAndDeclaredMethodsAreCalled(): void
    {
        $event = $this->event();
        $mapper = $this->mapper();

        # A YAML typo or a hostile map must not run arbitrary methods
        $this->assertNull($mapper->resolve($event, 'delete'));
        $this->assertNotNull(SchemaTestEvent::get()->byID($event->ID), 'delete() was not called');
        $this->assertNull($mapper->resolve($event, 'Holder.delete'));
        $this->assertNotNull(SiteTree::get()->byID($this->holder->ID), 'not on a related record either');

        $notices = $mapper->getNotices();
        $this->assertSame(['delete', 'delete'], array_column($notices, 'property'));
        $this->assertSame('method-not-allowed', $notices[0]['code']);

        # What is allowed: a method listed in schema_allowed_methods, a cast method, a getter, a relation
        $this->assertSame(Director::absoluteURL('/events/event/' . $event->ID), $mapper->resolve($event, 'AbsoluteLink'));
        $item = SchemaTestGetterItem::create(['Title' => 'Plain']);
        $item->write();
        $this->assertSame('Fish & chips', $mapper->resolve($item, 'Teaser'));
        $this->assertSame('Hello there', $mapper->resolve($item, 'Tagline'));
        $this->assertSame('Events', $mapper->resolve($event, 'Holder.Title'));
    }

    public function testAMethodThatIsNotAllowedIsAWarning(): void
    {
        $event = $this->event();
        Config::modify()->merge(SchemaTestEvent::class, 'schema_map', ['keywords' => 'delete']);
        $warnings = PageSchema::create($this->holder, $event)->getWarnings();

        $notices = array_values(array_filter($warnings, fn ($w) => ($w['code'] ?? null) === 'method-not-allowed'));
        $this->assertCount(1, $notices);
        $this->assertSame('delete', $notices[0]['property']);
        $this->assertNotNull(SchemaTestEvent::get()->byID($event->ID));
    }

    public function testHtmlValueKeepsSimpleMarkupOnly(): void
    {
        $event = $this->event([
            'Description' => '<p class="intro">Come <strong>and</strong> <a href="/x" onclick="y()">see</a> &amp; enjoy</p>'
                . '<script>alert(1)</script><h2 style="color:red">Hours</h2><ul><li>Ten <em>till</em> four</li></ul>'
                . '<div><img src="/a.png">Free <b>entry</b></div>',
        ]);
        $node = $this->mapper()->resolve($event, ['html' => 'Description']);

        $this->assertSame(
            '<p>Come <strong>and</strong> see &amp; enjoy</p><h2>Hours</h2><ul><li>Ten <em>till</em> four</li></ul>Free <strong>entry</strong>',
            $node
        );
        $this->assertNull($this->mapper()->resolve($this->event(['Description' => '<p> </p>']), ['html' => 'Description']));
    }

    public function testEmptyValuesAreLeftOut(): void
    {
        $event = $this->event(['Language' => '', 'CategoryID' => 0, 'EndDate' => null, 'EndTime' => null]);
        $node = $this->mapper()->nodesFor($event)[0];

        foreach (['inLanguage', 'keywords', 'endDate', 'image', 'location', 'offers'] as $property) {
            $this->assertArrayNotHasKey($property, $node, "$property has no value, so it is not output");
        }
    }

    public function testManyManyToSchemaSourcesGivesTheirNodes(): void
    {
        $event = $this->event();
        $event->Places()->add($this->place('Main building', 'Utrecht'));
        $event->Places()->add($this->place('Annex', 'Amersfoort'));

        $node = $this->mapper()->nodesFor($event)[0];
        $this->assertCount(2, $node['location']);
        $first = $node['location'][0];
        $this->assertSame('Place', $first['@type']);
        $this->assertSame('Main building', $first['name']);
        # A nested map without `from` reads the same record
        $this->assertSame([
            '@type' => 'PostalAddress',
            'streetAddress' => 'Main street 1',
            'postalCode' => '1234 AB',
            'addressLocality' => 'Utrecht',
            'addressCountry' => 'NL',
        ], $first['address']);
        $this->assertSame('Amersfoort', $node['location'][1]['address']['addressLocality']);
    }

    public function testAPathThroughTwoListsGivesOneFlatList(): void
    {
        $first = $this->event();
        $first->Places()->add($this->place('Hall A', 'Utrecht'));
        $second = $this->event(['Title' => 'Second day']);
        $second->Places()->add($this->place('Hall B', 'Utrecht'));
        $second->Places()->add($this->place('Hall C', 'Utrecht'));

        # has_many Events, then many_many Places: one list of names, not a list per event
        $this->assertSame(['Hall A', 'Hall B', 'Hall C'], $this->mapper()->resolve($this->holder, 'Events.Places.Title'));
    }

    public function testHasManyThroughANestedMapGivesAList(): void
    {
        $event = $this->event();
        foreach (['Adult' => 12.5, 'Child' => 0] as $title => $price) {
            SchemaTestTicket::create(['Title' => $title, 'Price' => $price, 'EventID' => $event->ID])->write();
        }

        $offers = $this->mapper()->nodesFor($event)[0]['offers'];
        $this->assertCount(2, $offers);
        $this->assertSame('Offer', $offers[0]['@type']);
        $this->assertSame('Adult', $offers[0]['name']);
        $this->assertEquals(12.5, $offers[0]['price']);
        $this->assertSame('EUR', $offers[0]['priceCurrency']);
        # A price of 0 is a value (a free ticket), not an empty one
        $this->assertArrayHasKey('price', $offers[1]);
        $this->assertEquals(0, $offers[1]['price']);
    }

    public function testIdsAreStable(): void
    {
        $event = $this->event();
        $place = $this->place('Main building', 'Utrecht');
        $event->Places()->add($place);

        $first = $this->mapper()->nodesFor($event)[0];
        $again = $this->mapper()->nodesFor(SchemaTestEvent::get()->byID($event->ID))[0];
        $this->assertSame($first['@id'], $again['@id']);

        # A record without a link of its own is identified on the page that shows it, with its class, since the
        # page may show records of several classes with the same type and ID
        $this->assertSame(
            Director::absoluteURL('/events') . '#place-schematestplace-' . $place->ID,
            $first['location'][0]['@id']
        );

        # A page's own node uses the page link, without the ?stage=Stage a draft link carries
        $job = SchemaTestJobPage::create(['Title' => 'Welder', 'URLSegment' => 'welder']);
        $job->write();
        $node = $this->mapper($job)->nodesFor($job)[0];
        $this->assertSame(Director::absoluteURL('/welder') . '#jobposting-' . $job->ID, $node['@id']);
        $this->assertStringNotContainsString('?', $node['@id']);
    }

    public function testRecordsOfTwoClassesWithTheSameTypeAndIdDoNotShareAnId(): void
    {
        $context = SchemaContext::create($this->holder);
        $place = SchemaTestPlace::create(['ID' => 7]);
        $ticket = SchemaTestTicket::create(['ID' => 7]);
        $this->assertNotSame($context->recordId($place, 'Place'), $context->recordId($ticket, 'Place'));
        # A record with a link of its own needs no class: the link tells it apart
        $event = SchemaTestEvent::create(['ID' => 7, 'HolderID' => $this->holder->ID]);
        $this->assertSame(Director::absoluteURL('/events/event/7') . '#event-7', $context->recordId($event, 'Event'));
    }

    public function testPageSourceWithANestedMapAndADatetime(): void
    {
        $job = SchemaTestJobPage::create([
            'Title' => 'Welder',
            'URLSegment' => 'welder',
            'Content' => '<p>Join us.</p>',
            'DatePosted' => '2027-01-05',
            'ValidThrough' => '2027-02-01 17:00:00',
            'City' => 'Utrecht',
        ]);
        $job->write();
        # A Datetime column holds PHP's default timezone (see SchemaDatesTest); pinned to UTC here, so the stored
        # 17:00 is 18:00 in the site timezone
        $previous = date_default_timezone_get();
        date_default_timezone_set('UTC');
        try {
            $node = $this->mapper($job)->nodesFor($job)[0];
        } finally {
            date_default_timezone_set($previous);
        }

        $this->assertSame('JobPosting', $node['@type']);
        $this->assertSame('2027-01-05', $node['datePosted']);
        $this->assertSame('2027-02-01T18:00:00+01:00', $node['validThrough']);
        $this->assertSame(['@id' => $this->base() . '/#organization'], $node['hiringOrganization']);
        $this->assertSame(
            ['@type' => 'Place', 'address' => ['@type' => 'PostalAddress', 'addressLocality' => 'Utrecht', 'addressCountry' => 'NL']],
            $node['jobLocation']
        );
        # Nothing for an empty employment type
        $this->assertArrayNotHasKey('employmentType', $node);
    }

    public function testImagesBecomeImageObjectsWhenPublic(): void
    {
        $image = $this->createTestImage('event.png', 800, 600);
        $event = $this->event(['ImageID' => $image->ID]);
        $node = $this->mapper()->nodesFor($event)[0];

        $this->assertSame('ImageObject', $node['image']['@type']);
        $this->assertMatchesRegularExpression('#^https?://.+event\.png$#', $node['image']['contentUrl']);
        $this->assertSame($node['image']['contentUrl'], $node['image']['url']);
        $this->assertSame(800, $node['image']['width']);
        $this->assertSame(600, $node['image']['height']);

        # A draft-only image is in the protected store: a crawler cannot fetch it
        $image->doUnpublish();
        $node = $this->mapper()->nodesFor(SchemaTestEvent::get()->byID($event->ID))[0];
        $this->assertArrayNotHasKey('image', $node);
    }

    public function testProvideSchemaDataGivesSeveralNodesFromMethodData(): void
    {
        $page = SchemaTestCoursePage::create([
            'Title' => 'Courses',
            'URLSegment' => 'courses',
            'CourseCache' => json_encode([
                ['code' => 'W1', 'name' => 'Welding', 'summary' => 'Learn to weld.', 'runs' => [
                    ['mode' => 'onsite', 'date' => '2027-09-01', 'time' => '09:00'],
                ]],
                ['code' => 'P2', 'name' => 'Plumbing', 'summary' => 'Learn plumbing.'],
            ]),
        ]);
        $page->write();

        $nodes = $this->mapper($page)->nodesFor($page);
        $this->assertCount(2, $nodes);
        $this->assertSame('Course', $nodes[0]['@type']);
        $this->assertSame(Director::absoluteURL('/courses') . '#course-W1', $nodes[0]['@id']);
        $this->assertSame('2027-09-01T09:00:00+02:00', $nodes[0]['hasCourseInstance'][0]['startDate']);
        # An empty list from the method is left out like any other empty value
        $this->assertArrayNotHasKey('hasCourseInstance', $nodes[1]);
    }

    public function testProvideSchemaDataMayReturnNothing(): void
    {
        $page = SchemaTestCoursePage::create(['Title' => 'Courses', 'URLSegment' => 'courses', 'CourseCache' => '']);
        $page->write();
        $this->assertSame([], $this->mapper($page)->nodesFor($page));
    }

    public function testUpdateSchemaDataHookTweaksEachNode(): void
    {
        SchemaTestDataExtension::$active = true;
        $node = $this->mapper()->nodesFor($this->event())[0];
        $this->assertSame('16-', $node['typicalAgeRange']);
        $this->assertSame('Events', $node['seenPage'], 'the hook gets the context');
    }

    public function testRecordsThatAreNoSourceGiveNothing(): void
    {
        $item = SeoTestItem::create(['Title' => 'Plain']);
        $item->write();
        $this->assertSame([], $this->mapper()->nodesFor($item));
        # A page without SchemaSource or provideSchemaData neither
        $this->assertSame([], $this->mapper()->nodesFor($this->holder));
    }

    public function testContextIds(): void
    {
        $ctx = SchemaContext::create($this->holder);
        $this->assertSame($this->base() . '/#website', $ctx->siteId('website'));
        $this->assertSame(Director::absoluteURL('/events') . '#webpage', $ctx->pageId('webpage'));
    }
}
