<?php

namespace Restruct\Silverstripe\SEO\Tests\Schema;

use Restruct\Silverstripe\SEO\Tests\SeoTestImages;
use Restruct\Silverstripe\SEO\Tests\Stub\Schema\SchemaTestCategory;
use Restruct\Silverstripe\SEO\Tests\Stub\Schema\SchemaTestCoursePage;
use Restruct\Silverstripe\SEO\Tests\Stub\Schema\SchemaTestEvent;
use Restruct\Silverstripe\SEO\Tests\Stub\Schema\SchemaTestFaultyPage;
use Restruct\Silverstripe\SEO\Tests\Stub\Schema\SchemaTestHolderPage;
use Restruct\Silverstripe\SEO\Tests\Stub\Schema\SchemaTestJobPage;
use Restruct\Silverstripe\SEO\Tests\Stub\Schema\SchemaTestPlace;
use Restruct\Silverstripe\SEO\Tests\Stub\Schema\SchemaTestTicket;
use Restruct\Silverstripe\SEO\Tests\Stub\SeoTestPage;
use SilverStripe\Assets\Dev\TestAssetStore;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Control\Director;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Core\Config\Config;
use SilverStripe\Dev\FunctionalTest;
use SilverStripe\SiteConfig\SiteConfig;
use SilverStripe\View\SSViewer;

/**
 * The structured data a visitor receives: one JSON-LD script per page holding one `@graph`, with the site nodes,
 * the WebPage, its breadcrumb, the page's own source, a shown record as the main entity, included records and an
 * ItemList; incomplete nodes left out, and nothing at all when switched off.
 */
class StructuredDataOutputTest extends FunctionalTest
{
    use SeoTestImages;

    protected $usesDatabase = true;

    protected static $use_draft_site = false;

    protected static $extra_dataobjects = [
        SeoTestPage::class,
        SchemaTestHolderPage::class,
        SchemaTestEvent::class,
        SchemaTestPlace::class,
        SchemaTestTicket::class,
        SchemaTestCategory::class,
        SchemaTestJobPage::class,
        SchemaTestCoursePage::class,
        SchemaTestFaultyPage::class,
    ];

    protected function setUp(): void
    {
        parent::setUp();
        TestAssetStore::activate('StructuredDataOutputTest');
        SSViewer::set_themes(['restruct/silverstripe-seo:/tests/themes/seotest', '$default']);
        SiteTree::config()->set('seo_schema_timezone', 'Europe/Amsterdam');
        SchemaTestFaultyPage::$mode = 'none';

        $config = SiteConfig::current_site_config();
        $config->Title = 'Example Site';
        $config->write();
    }

    protected function tearDown(): void
    {
        TestAssetStore::reset();
        parent::tearDown();
    }

    private function publish(SiteTree $page): SiteTree
    {
        $page->write();
        $page->publishRecursive();
        return $page;
    }

    private function base(): string
    {
        return rtrim(Director::absoluteBaseURL(), '/');
    }

    /**
     * Every JSON-LD script in the head, decoded.
     *
     * @return array[]
     */
    private function scripts(HTTPResponse $response): array
    {
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        preg_match('#<head>(.*)</head>#s', (string) $response->getBody(), $head);
        preg_match_all('#<script type="application/ld\+json">(.*?)</script>#s', $head[1] ?? '', $matches);
        return array_map(fn ($json) => json_decode($json, true, 512, JSON_THROW_ON_ERROR), $matches[1]);
    }

    /**
     * The nodes of the page's single graph, by @id (nodes without one under a running number).
     *
     * @return array<string|int,array>
     */
    private function graph(string $url): array
    {
        $scripts = $this->scripts($this->get($url));
        $this->assertCount(1, $scripts, 'one JSON-LD script per page');
        $this->assertSame('https://schema.org', $scripts[0]['@context']);
        $nodes = [];
        foreach ($scripts[0]['@graph'] as $node) {
            if (isset($node['@id'])) {
                $this->assertArrayNotHasKey($node['@id'], $nodes, 'every @id appears once');
                $nodes[$node['@id']] = $node;
            } else {
                $nodes[] = $node;
            }
        }
        return $nodes;
    }

    private function nodesOfType(array $graph, string $type): array
    {
        return array_values(array_filter($graph, fn ($node) => ($node['@type'] ?? null) === $type));
    }

    private function holderWithEvent(bool $withPlace = true): array
    {
        $holder = $this->publish(SchemaTestHolderPage::create(['Title' => 'Events', 'URLSegment' => 'events']));
        $event = SchemaTestEvent::create([
            'Title' => 'Open day',
            'StartDate' => '2027-07-03',
            'StartTime' => '10:00:00',
            'HolderID' => $holder->ID,
        ]);
        $event->write();
        if ($withPlace) {
            $place = SchemaTestPlace::create(['Title' => 'Main building', 'Street' => 'Main street 1', 'City' => 'Utrecht']);
            $place->write();
            $event->Places()->add($place);
        }
        return [$holder, $event];
    }

    public function testEveryPageHasTheSiteNodesAndItsWebPage(): void
    {
        $this->publish(SeoTestPage::create(['Title' => 'About us', 'URLSegment' => 'about-us', 'MetaDescription' => 'Who we are.']));
        $graph = $this->graph('about-us');
        $base = $this->base();
        $pageURL = Director::absoluteURL('/about-us');

        $this->assertSame('WebSite', $graph[$base . '/#website']['@type']);
        $this->assertSame('Example Site', $graph[$base . '/#website']['name']);
        $this->assertSame($base . '/', $graph[$base . '/#website']['url']);
        $this->assertSame(['@id' => $base . '/#organization'], $graph[$base . '/#website']['publisher']);

        $this->assertSame('Organization', $graph[$base . '/#organization']['@type']);
        $this->assertSame('Example Site', $graph[$base . '/#organization']['name']);
        $this->assertSame($base . '/', $graph[$base . '/#organization']['url']);

        $page = $graph[$pageURL . '#webpage'];
        $this->assertSame('WebPage', $page['@type']);
        $this->assertSame($pageURL, $page['url']);
        $this->assertSame('About us', $page['name']);
        $this->assertSame('Who we are.', $page['description']);
        $this->assertSame(['@id' => $base . '/#website'], $page['isPartOf']);
        # No page source and nothing shown: no main entity
        $this->assertArrayNotHasKey('mainEntity', $page);
    }

    public function testIdsAreStableBetweenRequests(): void
    {
        $this->publish(SeoTestPage::create(['Title' => 'About us', 'URLSegment' => 'about-us']));
        $first = array_keys($this->graph('about-us'));
        # A tracking parameter changes nothing
        $second = array_keys($this->graph('about-us?utm_source=newsletter'));
        $this->assertSame($first, $second);
    }

    public function testWebPageFollowsTheCanonicalOfActionsAndKeptParameters(): void
    {
        $this->publish(SeoTestPage::create(['Title' => 'About us', 'URLSegment' => 'about-us']));

        # An action URL is a page of its own, with its own canonical; the WebPage describes that URL
        $url = Director::absoluteURL('/about-us/detail');
        $graph = $this->graph('about-us/detail');
        $this->assertArrayHasKey($url . '#webpage', $graph);
        $this->assertSame($url, $graph[$url . '#webpage']['url']);

        # A parameter kept in the canonical (pagination) makes another page too
        SiteTree::config()->set('seo_canonical_query_params', ['page']);
        $url = Director::absoluteURL('/about-us') . '?page=2';
        $graph = $this->graph('about-us?page=2&utm_source=x');
        $this->assertArrayHasKey($url . '#webpage', $graph);
        $this->assertSame($url, $graph[$url . '#webpage']['url']);
    }

    public function testWebPageSubtypeComesFromThePageClass(): void
    {
        Config::modify()->set(SeoTestPage::class, 'schema_webpage_type', 'AboutPage');
        $this->publish(SeoTestPage::create(['Title' => 'About us', 'URLSegment' => 'about-us']));
        $graph = $this->graph('about-us');
        $this->assertSame('AboutPage', $graph[Director::absoluteURL('/about-us') . '#webpage']['@type']);
    }

    public function testOrganizationFromSiteConfig(): void
    {
        $logo = $this->createTestImage('logo.png', 300, 300);
        $config = SiteConfig::current_site_config();
        $config->SeoLogoID = $logo->ID;
        $config->SeoSameAs = "https://social.example/our-page\n\nnot a url\nhttps://video.example/channel ";
        $config->write();
        # A project adds a property to the shipped map
        Config::modify()->merge(SiteConfig::class, 'schema_map', ['alternateName' => ['literal' => 'ES']]);

        $this->publish(SeoTestPage::create(['Title' => 'About us', 'URLSegment' => 'about-us']));
        $organization = $this->graph('about-us')[$this->base() . '/#organization'];

        $this->assertSame('ImageObject', $organization['logo']['@type']);
        $this->assertMatchesRegularExpression('#logo\.png$#', $organization['logo']['contentUrl']);
        # One URL per line; anything that is not an http(s) URL is skipped
        $this->assertSame(['https://social.example/our-page', 'https://video.example/channel'], $organization['sameAs']);
        $this->assertSame('ES', $organization['alternateName']);
    }

    public function testWithoutASiteNameThereIsNoOrganizationAndNothingPointsAtIt(): void
    {
        $config = SiteConfig::current_site_config();
        $config->Title = '';
        $config->write();
        $this->publish(SeoTestPage::create(['Title' => 'About us', 'URLSegment' => 'about-us']));
        $graph = $this->graph('about-us');

        $this->assertArrayNotHasKey($this->base() . '/#organization', $graph);
        # The website needs a name as well, so it goes too, and so does the page's reference to it
        $this->assertArrayNotHasKey($this->base() . '/#website', $graph);
        $this->assertArrayNotHasKey('isPartOf', $graph[Director::absoluteURL('/about-us') . '#webpage']);
    }

    public function testBreadcrumbFromTheTrail(): void
    {
        $home = $this->publish(SeoTestPage::create(['Title' => 'Home', 'URLSegment' => 'home']));
        $parent = $this->publish(SeoTestPage::create(['Title' => 'Services', 'URLSegment' => 'services']));
        $this->publish(SeoTestPage::create([
            'Title' => 'Repairs and maintenance',
            'MenuTitle' => 'Repairs',
            'URLSegment' => 'repairs',
            'ParentID' => $parent->ID,
        ]));

        $url = Director::absoluteURL('/services/repairs');
        $graph = $this->graph('services/repairs');
        $breadcrumb = $graph[$url . '#breadcrumb'];
        $this->assertSame('BreadcrumbList', $breadcrumb['@type']);
        $this->assertSame([
            # Each crumb links to the page's canonical URL (for the home page: the site root, as the page link has it)
            ['@type' => 'ListItem', 'position' => 1, 'name' => 'Home', 'item' => $home->SeoCanonicalURL()],
            ['@type' => 'ListItem', 'position' => 2, 'name' => 'Services', 'item' => Director::absoluteURL('/services')],
            ['@type' => 'ListItem', 'position' => 3, 'name' => 'Repairs', 'item' => $url],
        ], $breadcrumb['itemListElement']);
        $this->assertSame(['@id' => $url . '#breadcrumb'], $graph[$url . '#webpage']['breadcrumb']);

        # A top-level page: home and the page
        $this->assertCount(2, $this->graph('services')[Director::absoluteURL('/services') . '#breadcrumb']['itemListElement']);

        # The home page has no trail worth showing
        $home = $this->graph('');
        $this->assertSame([], $this->nodesOfType($home, 'BreadcrumbList'));
        $this->assertArrayNotHasKey('breadcrumb', $this->nodesOfType($home, 'WebPage')[0]);
    }

    public function testPageSourceIsTheMainEntityAndReferencesTheOrganization(): void
    {
        $job = $this->publish(SchemaTestJobPage::create([
            'Title' => 'Welder',
            'URLSegment' => 'welder',
            'Content' => '<p>Join us.</p>',
            'DatePosted' => '2027-01-05',
            'City' => 'Utrecht',
        ]));
        $url = Director::absoluteURL('/welder');
        $graph = $this->graph('welder');
        $id = $url . '#jobposting-' . $job->ID;

        $this->assertSame('JobPosting', $graph[$id]['@type']);
        $this->assertSame(['@id' => $this->base() . '/#organization'], $graph[$id]['hiringOrganization']);
        $this->assertArrayHasKey($this->base() . '/#organization', $graph, 'the referenced node is in the graph');
        $this->assertSame(['@id' => $id], $graph[$url . '#webpage']['mainEntity']);
    }

    public function testIncompleteNodeIsNotOutputAndNotReferenced(): void
    {
        # No datePosted: a required JobPosting property
        $job = $this->publish(SchemaTestJobPage::create([
            'Title' => 'Welder',
            'URLSegment' => 'welder',
            'Content' => '<p>Join us.</p>',
            'City' => 'Utrecht',
        ]));
        $url = Director::absoluteURL('/welder');
        $graph = $this->graph('welder');

        $this->assertSame([], $this->nodesOfType($graph, 'JobPosting'));
        $this->assertArrayNotHasKey('mainEntity', $graph[$url . '#webpage']);

        # The warning says what is missing and that the node was left out
        $warnings = SchemaTestJobPage::get()->byID($job->ID)->getSchemaWarnings();
        $required = array_values(array_filter($warnings, fn ($w) => $w['type'] === 'JobPosting' && $w['severity'] === 'required'));
        $this->assertSame(['datePosted'], array_column($required, 'property'));
        $this->assertFalse($required[0]['emitted']);
    }

    public function testWithoutAnOrganizationAJobPostingIsIncompleteToo(): void
    {
        $config = SiteConfig::current_site_config();
        $config->Title = '';
        $config->write();
        $this->publish(SchemaTestJobPage::create([
            'Title' => 'Welder',
            'URLSegment' => 'welder',
            'Content' => '<p>Join us.</p>',
            'DatePosted' => '2027-01-05',
            'City' => 'Utrecht',
        ]));
        # hiringOrganization pointed at the organisation that was not output: the job posting cannot stand
        $this->assertSame([], $this->nodesOfType($this->graph('welder'), 'JobPosting'));
    }

    public function testShownRecordIsTheMainEntity(): void
    {
        [, $event] = $this->holderWithEvent();
        $url = Director::absoluteURL('/events/event/' . $event->ID);
        $graph = $this->graph('events/event/' . $event->ID);
        $id = $url . '#event-' . $event->ID;

        $this->assertSame('Event', $graph[$id]['@type']);
        $this->assertSame('2027-07-03T10:00:00+02:00', $graph[$id]['startDate']);
        $this->assertSame('Main building', $graph[$id]['location'][0]['name']);

        # The WebPage describes the record's URL and points at the record
        $page = $graph[$url . '#webpage'];
        $this->assertSame($url, $page['url']);
        $this->assertSame('Open day', $page['name']);
        $this->assertSame(['@id' => $id], $page['mainEntity']);
        # The holder's listing is not repeated on the record's page
        $this->assertSame([], $this->nodesOfType($graph, 'ItemList'));

        # The breadcrumb ends with the record
        $items = $graph[$url . '#breadcrumb']['itemListElement'];
        $this->assertSame(['@type' => 'ListItem', 'position' => 2, 'name' => 'Open day', 'item' => $url], end($items));
    }

    public function testShownRecordWithoutRequiredDataIsLeftOut(): void
    {
        [, $event] = $this->holderWithEvent(false);
        $url = Director::absoluteURL('/events/event/' . $event->ID);
        $graph = $this->graph('events/event/' . $event->ID);

        $this->assertSame([], $this->nodesOfType($graph, 'Event'));
        $this->assertArrayNotHasKey('mainEntity', $graph[$url . '#webpage']);
    }

    public function testListingEmitsAnItemList(): void
    {
        [, $event] = $this->holderWithEvent();
        $url = Director::absoluteURL('/events');
        $graph = $this->graph('events');

        $this->assertSame('CollectionPage', $graph[$url . '#webpage']['@type']);
        $list = $graph[$url . '#itemlist'];
        $this->assertSame('ItemList', $list['@type']);
        $this->assertSame(
            [['@type' => 'ListItem', 'position' => 1, 'url' => Director::absoluteURL('/events/event/' . $event->ID)]],
            $list['itemListElement']
        );
        $this->assertSame(['@id' => $url . '#itemlist'], $graph[$url . '#webpage']['mainEntity']);
        # The events themselves are not included unless the class asks for it
        $this->assertSame([], $this->nodesOfType($graph, 'Event'));
    }

    public function testIncludedRecordsBecomeNodesOfTheirOwn(): void
    {
        Config::modify()->set(SchemaTestHolderPage::class, 'schema_includes', ['Events']);
        [, $event] = $this->holderWithEvent();
        $graph = $this->graph('events');

        $id = Director::absoluteURL('/events/event/' . $event->ID) . '#event-' . $event->ID;
        $this->assertSame('Event', $graph[$id]['@type']);
        # The same organisation node, once, however many nodes point at it
        $this->assertCount(1, $this->nodesOfType($graph, 'Organization'));
    }

    public function testIncludedRecordsAreLimited(): void
    {
        $this->assertSame(50, SchemaTestHolderPage::config()->get('schema_includes_limit'), 'a default limit');
        Config::modify()->set(SchemaTestHolderPage::class, 'schema_includes', ['Events']);
        Config::modify()->set(SchemaTestHolderPage::class, 'schema_includes_limit', 2);
        [$holder] = $this->holderWithEvent();
        foreach (['Second', 'Third'] as $title) {
            $event = SchemaTestEvent::create(['Title' => $title, 'StartDate' => '2027-07-04', 'HolderID' => $holder->ID]);
            $event->write();
            $event->Places()->add(SchemaTestPlace::get()->first());
        }

        $this->assertCount(2, $this->nodesOfType($this->graph('events'), 'Event'));
    }

    public function testNodesFromAMethodReachTheHead(): void
    {
        $this->publish(SchemaTestCoursePage::create([
            'Title' => 'Courses',
            'URLSegment' => 'courses',
            'CourseCache' => json_encode([['code' => 'W1', 'name' => 'Welding', 'summary' => 'Learn to weld.']]),
        ]));
        $graph = $this->graph('courses');
        $this->assertSame('Welding', $graph[Director::absoluteURL('/courses') . '#course-W1']['name']);
    }

    public function testDataCannotBreakOutOfTheScriptElement(): void
    {
        $title = 'Fish </script><script>alert(1)</script> & <!-- chips';
        $this->publish(SeoTestPage::create(['Title' => $title, 'URLSegment' => 'fish']));
        $response = $this->get('fish');
        $body = (string) $response->getBody();

        $this->assertStringNotContainsString('<script>alert(1)', $body);
        $this->assertSame(1, preg_match('#<script type="application/ld\+json">(.*?)</script>#s', $body, $match));
        $this->assertStringNotContainsString('<', $match[1]);
        # And the value arrives intact
        $graph = $this->graph('fish');
        $this->assertSame($title, $graph[Director::absoluteURL('/fish') . '#webpage']['name']);
    }

    public function testAFailingSourceCostsTheStructuredDataNotThePage(): void
    {
        SchemaTestFaultyPage::$mode = 'throw';
        $this->publish(SchemaTestFaultyPage::create(['Title' => 'Measurements', 'URLSegment' => 'measurements']));
        $response = $this->get('measurements');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringNotContainsString('application/ld+json', (string) $response->getBody());
        # The rest of the head is still there
        $this->assertStringContainsString('<link rel="canonical"', (string) $response->getBody());
    }

    public function testOutputCanBeSwitchedOff(): void
    {
        SiteTree::config()->set('seo_output_structured_data', false);
        $this->publish(SeoTestPage::create(['Title' => 'About us', 'URLSegment' => 'about-us']));
        $this->assertSame([], $this->scripts($this->get('about-us')));
    }

    public function testNoStructuredDataOnAnErrorStatus(): void
    {
        $this->publish(SeoTestPage::create(['Title' => 'About us', 'URLSegment' => 'about-us']));
        $response = $this->get('about-us/gone');
        $this->assertSame(410, $response->getStatusCode());
        $this->assertStringNotContainsString('application/ld+json', (string) $response->getBody());
    }
}
