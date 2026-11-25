# Silverstripe SEO

Search and sharing metadata for Silverstripe CMS 6 pages, with sensible fallbacks:

- **Meta title**, separate from the page title, with the site name appended (`About us | Your Site`).
- **Meta description** fallback: when an editor leaves it empty, the first paragraph of the content, cut at a
  word boundary (about 155 characters).
- **Robots per page**: noindex, nofollow, nosnippet and noarchive, output as `<meta name="robots">` **and** as an
  `X-Robots-Tag` response header.
- **Canonical URL**: self-referencing by default, with an optional override, output as `<link rel="canonical">`
  **and** as a `Link: <...>; rel="canonical"` response header.
- **Open Graph**: og:title, og:description, og:image (cropped to 1200 x 630), og:url, og:type, og:site_name, plus
  `twitter:card`. Each falls back sensibly (sharing description to meta description, page image to a hook to a
  site-wide default image).
- **Records shown on a page** (`/courses/item/12`) can supply all of the above for themselves.
- **Structured data** (JSON-LD): one `@graph` per page with WebSite, Organization, WebPage and BreadcrumbList,
  plus nodes mapped in YAML from the records you already have (events, vacancies, courses). Nodes missing
  required properties are left out, and the page editor shows a preview with warnings.
- **Sitemap bridge**: with [wilr/silverstripe-googlesitemaps](https://github.com/wilr/silverstripe-googlesitemaps)
  installed, noindex pages are left out of `sitemap.xml`.

Planned for later versions: page checks on the rendered page (Elemental blocks included), a site-wide report,
and optional AI suggestions.

## Requirements

- PHP 8.3+
- Silverstripe CMS 6 (`silverstripe/framework` ^6, `silverstripe/cms` ^6)

## Installation

```bash
composer require restruct/silverstripe-seo
vendor/bin/sake db:build --flush
```

The fields appear in the page editor under **Search engines and sharing** (core's Metadata toggle below the
content), and the site-wide defaults under **Settings > SEO**.

Set the site's base URL and allowed hosts in `.env`. The canonical URL and `og:url` are absolute, and without a
base URL Silverstripe builds them from the Host header of the request, so a request with another host name would
get canonicals pointing at that host:

```bash
SS_BASE_URL="https://www.example.com"
SS_ALLOWED_HOSTS="www.example.com"
```

## Theme integration

Use `$MetaTags` in the `<head>` of your page template. Either let it output the title:

```ss
<head>
    $MetaTags
</head>
```

or keep your own `<title>` tag and feed it from `$SeoTitle`, which already contains the site name:

```ss
<head>
    $MetaTags(false)
    <title>$SeoTitle</title>
</head>
```

A template that builds its own title from `$MetaTitle` (as the default startup theme does:
`<% if $MetaTitle %>$MetaTitle<% else %>$Title<% end_if %> | $SiteConfig.Title`) keeps working, because the
column is called `MetaTitle`, but it bypasses the title suffix setting and the duplicate-site-name guard. Switch
to `$SeoTitle` to get those.

The response headers (`X-Robots-Tag`, `Link`) need nothing from the theme.

A theme that already prints its own canonical or Open Graph tags should switch the module's output off, so they
do not appear twice:

```yml
SilverStripe\CMS\Model\SiteTree:
  # No <link rel="canonical"> and no Link header ($SeoCanonicalURL stays available)
  seo_output_canonical: false
  # No og:* tags (og:url included) and no twitter:card
  seo_output_open_graph: false
```

Values available in templates: `$SeoTitle`, `$SeoPageTitle` (without site name), `$SeoDescription`, `$SeoRobots`,
`$SeoCanonicalURL`, `$SeoOGDescription`, `$SeoImage`.

## Configuration

All settings live on `SilverStripe\CMS\Model\SiteTree` and can be set per page class:

```yml
SilverStripe\CMS\Model\SiteTree:
  # Between page title and site name
  seo_title_separator: ' - '
  # Set to false to leave the site name out of <title>
  seo_title_append_site_name: true
  # Derive a description from the content when none was typed, and its maximum length
  seo_description_fallback: true
  seo_description_fallback_length: 155
  # Query parameters that stay in the self-referencing canonical (all others are dropped)
  seo_canonical_query_params:
    - start
  # Parameters stripped from a shown record's own link (shell wildcards); this list is added to the defaults
  seo_tracking_query_params:
    - ref
  # Crop size of the sharing image; an empty array uses the original
  seo_og_image_size:
    width: 1200
    height: 630
  # twitter:card when there is an image (without one it is always "summary"); empty string leaves it out
  seo_twitter_card: summary_large_image
  # Print the canonical (tag and Link header) and the Open Graph and twitter:card tags; see Theme integration
  seo_output_canonical: true
  seo_output_open_graph: true
  # noindex, nofollow on every page (meta tag and header) when the environment is not live, for a test or
  # acceptance site that is reachable from outside; its sitemap is then empty as well
  seo_noindex_non_live: false
  # Print the structured data (JSON-LD); see Structured data
  seo_output_structured_data: true
  # Timezone of the dates and times in structured data; empty uses PHP's default timezone
  seo_schema_timezone: 'Europe/Amsterdam'

# og:type per page class
App\Model\ArticlePage:
  seo_og_type: article
```

Site-wide, in the CMS under Settings > SEO:

- **Title suffix**: replaces the site title after every page title (e.g. a shorter brand name).
- **Default sharing image**: used when a page has no sharing image of its own.
- **Logo** and **Profiles on other sites** (one URL per line): the Organization node's `logo` and `sameAs` in
  structured data.

A sharing image is fetched anonymously by the sites a page is shared on, so only a public image is used: one
that is published, in the public asset store and viewable by anyone. A draft-only image, or one restricted to
logged-in members, is skipped and the next one in the chain is used.

## Canonical URLs

- A page's canonical is its own URL, without query string except the parameters in
  `seo_canonical_query_params`.
- An action URL is its own canonical: everything after the page's path is kept (`/blog/archive/2024/05/12` for
  a url_handler `archive/$Year!/$Month/$Day`), with the action segment lowercased. On the homepage the `home`
  segment is kept (`/home/archive/...`), since that is where the action lives.
- Error pages, error responses (status 400 or higher) and redirects (including redirector pages) get no
  canonical, no `og:url` and no `Link` header; redirects also get no `X-Robots-Tag`.

## Records shown on a page

A holder page that shows a record on a sub-action declares that record, before rendering, as the SEO subject:

```php
public function item(HTTPRequest $request)
{
    $course = Course::get()->byID((int) $request->param('ID'));
    if (!$course) {
        return $this->httpError(404);
    }
    $this->setSeoSubject($course);
    return ['Course' => $course];
}
```

Title, description, canonical, Open Graph and robots then describe the record. A controller can also define its
own `getSeoSubject(): ?DataObject` instead of calling the setter.

The record is read by duck-typing, so most DataObjects need no changes: `MetaTitle` or `Title`; `MetaDescription`
or the first paragraph of `Content`; `SEOFBdescription` for sharing; `AbsoluteLink()` or `Link()` for the
canonical, with tracking parameters (`utm_*`, `gclid`, `fbclid`, ...) removed; an `OGImage` has_one or a
`getSeoImage()` method; and the `MetaRobots*` boolean fields if it has them (otherwise the page's robots apply).
For full control, implement `Restruct\Silverstripe\SEO\Model\SeoSubject`.

A record without a sharing image of its own does not take the image of the page that lists it (that one
illustrates the listing): it goes to the `updateSeoImageFallback` hook and then to the site default image.

A **virtual page** is a duplicate by definition: its canonical points at the page it copies. It also copies the
SEO fields of that page, so they are read-only on the virtual page, as core's own copied fields are.

## Structured data

Every page gets one `<script type="application/ld+json">` in the head (through `$MetaTags`), holding one
`@graph`. Nodes point at each other by `@id`, so the organisation is described once and every event or job
posting refers to it.

What a page outputs:

| Node | `@id` | When |
|---|---|---|
| WebSite | `https://example.com/#website` | always (needs a site title) |
| Organization | `https://example.com/#organization` | when SiteConfig gives it a name |
| WebPage (or a subtype) | `<canonical URL>#webpage` | always |
| BreadcrumbList | `<canonical URL>#breadcrumb` | when the trail (home page in front) has two or more entries |
| the page's own node | `<page link>#<type>-<ID>` | when the page class is a source (below) |
| the shown record's node | `<record link>#<type>-<ID>` | when the controller declared a record with `setSeoSubject()` |
| included records | their own ids | for the relations and methods in `schema_includes`, at most `schema_includes_limit` (50) |
| ItemList | `<canonical URL>#itemlist` | for the relation or method in `schema_item_list` (at most `schema_item_list_limit`, 100), on the page itself |

The **`@id` rule**:
- site nodes are `<base URL>/#<name>`;
- nodes of a page view are `<canonical URL>#<name>`: the page's canonical, so an action URL (`/about/detail`)
  and a parameter kept by `seo_canonical_query_params` (`?page=2`) have a WebPage of their own, or the shown
  record's canonical when the page shows a record;
- records are `<record link>#<type in lowercase>-<ID>`; a record without a `Link()` is identified on the page's
  link with its class added (`/events#place-venue-12`), since a page can show records of several classes mapped
  to one type. Record links are taken without their query string, so ids are the same in draft and live.

The WebPage's `mainEntity` is chosen after incomplete nodes were left out (see below): the shown record, else
the page's own node, else the ItemList. On a page that shows a record, the record takes the page's place as the
content source: its node, its `schema_includes`, and it ends the breadcrumb; the page's own node and ItemList are
left out there. The ItemList holds the URLs of the records; full nodes per entry may become an option later.

No structured data is printed on an error page or an error status. Switch it off entirely with
`seo_output_structured_data: false`. When building it fails (a `provideSchemaData()` that throws), the error is
logged at warning level and the page is served without it; a value JSON cannot hold (NAN, INF) is left out with a
warning, the rest is printed.

**Who sees what.** Records reached through a relation, `schema_includes` or `schema_item_list` are output only
when an *anonymous* visitor may see them (`canView()`): the head is cached by proxies and static caches, so it
must never depend on who is logged in. This is checked for pages always, and for other classes when they define
`canView()` themselves (DataObject's own `canView()` admits administrators only, which would hide every plain
record); `schema_check_can_view: true` or `false` on a class decides explicitly.

**Cost.** The graph is built on every page view; nothing is cached yet. Included records are capped and the
relations their map reads are eager-loaded, but a listing with many records still costs queries; keep
`schema_includes` for pages that list a modest number of records.

### Records as sources

Apply the `SchemaSource` extension to any page type or DataObject and map its fields in YAML. There is no
table of its own: the record's fields are the data.

An event with a date and time in separate fields and several venues:

```yml
App\Model\Event:
  extensions:
    - Restruct\Silverstripe\SEO\Extensions\SchemaSource
  schema_type: EducationEvent
  schema_map:
    name: Title
    description: Content                         # HTML becomes plain text, one line per paragraph
    url: AbsoluteLink                            # a method, allowed below
    startDate: { date: StartDate, time: StartTime }
    endDate: { date: EndDate, time: EndTime }
    eventStatus: { literal: 'https://schema.org/EventScheduled' }
    eventAttendanceMode: { literal: 'https://schema.org/OfflineEventAttendanceMode' }
    image: Image                                 # a has_one Image becomes an ImageObject
    organizer: { ref: organization }
    location: Venues                             # many_many to a class that is a source itself
    offers:                                      # a nested map over a has_many
      '@type': Offer
      from: Tickets
      name: Title
      price: Price
      priceCurrency: { literal: EUR }
  # A map calls only fields, relations, getters and methods in `casting`; other methods are listed here
  schema_allowed_methods:
    - AbsoluteLink

App\Model\Venue:
  extensions:
    - Restruct\Silverstripe\SEO\Extensions\SchemaSource
  schema_type: Place
  schema_map:
    name: Title
    address:
      '@type': PostalAddress
      streetAddress: Street
      postalCode: PostalCode
      addressLocality: City
      addressCountry: Country
    geo:
      '@type': GeoCoordinates
      latitude: Latitude
      longitude: Longitude

# The holder page shows one event on /events/event/12 (with setSeoSubject()) and lists them all
App\Page\EventHolder:
  schema_webpage_type: CollectionPage
  schema_item_list: Events
```

A vacancy page whose hiring organisation is the site's Organization node:

```yml
App\Page\VacancyPage:
  extensions:
    - Restruct\Silverstripe\SEO\Extensions\SchemaSource
  schema_type: JobPosting
  schema_map:
    title: Title
    description: { html: Content }               # Google shows paragraphs and lists here
    datePosted: DatePosted                       # a Date field: 2027-01-05
    validThrough: ClosingDate                    # a Datetime field, with the site timezone's offset
    employmentType: EmploymentType
    hiringOrganization: { ref: organization }
    jobLocation:
      '@type': Place
      address:
        '@type': PostalAddress
        addressLocality: City
        addressCountry: { literal: NL }
```

A fully remote job has no `jobLocation`; map `applicantLocationRequirements` (a Country or State) and
`jobLocationType: { literal: TELECOMMUTE }` instead, which the registry accepts in its place.

The value of a map entry is one of:

- `FieldName`, `MethodName`, or a dotted path through relations (`Venue.City`), read as a template reads it
  (an overriding getter wins, `casting` applies); a has_many or many_many on the way gives a list, and a path
  through two lists (`Events.Venues.City`) one flat list. Only database fields, relations, `get<Name>()`
  getters, methods in `casting` and methods in `schema_allowed_methods` are read: anything else (`delete`, a
  typo) is not called and shows up as a warning;
- `{ literal: value }`: the value as written;
- `{ ref: organization }`: a reference to a site node (`organization`, `website`) or to a node of the page view
  (`webpage`, `breadcrumb`, `itemlist`); a full URL is used as it is;
- `{ date: DateField, time: TimeField }`: one ISO 8601 value with the offset of `seo_schema_timezone` on that
  date (so daylight saving time is right); without a time, just the date;
- `{ html: Field }`: the field's HTML reduced to `p`, `br`, `ul`, `ol`, `li`, `strong`, `em` and `h2` to `h4`,
  without attributes (for a JobPosting description);
- a nested map, with its own `@type` and optionally `from: Relation` to read from another record (a list gives
  a list of nodes);
- a YAML list of any of these: a list of values (`sameAs: [FacebookURL, LinkedInURL]`).

Values are converted for JSON: Date fields as `2027-01-05`; Datetime fields, which Silverstripe stores in PHP's
default timezone, as that moment in `seo_schema_timezone`; HTML as plain text,
images as an ImageObject (only public, published images, as for the sharing image), a related record that is a
source itself as its own node. Empty values are left out. In YAML a project can drop an inherited property by
setting it to `null`.

### Data from a method

For data that has no fields, such as course dates from an external API cached on a page, define
`provideSchemaData()` on the record. It returns one node or a list of nodes, and wins over a map:

```php
use Restruct\Silverstripe\SEO\Schema\SchemaContext;

public function provideSchemaData(SchemaContext $ctx): ?array
{
    $nodes = [];
    foreach ($this->getCachedCourses() as $course) {
        $nodes[] = [
            '@type' => 'Course',
            '@id' => $ctx->pageId('course-' . $course['code']),
            'name' => $course['name'],
            'description' => $course['summary'],
            'provider' => ['@id' => $ctx->siteId('organization')],
            'hasCourseInstance' => array_map(fn (array $run) => [
                '@type' => 'CourseInstance',
                'courseMode' => $run['mode'],
                'startDate' => $ctx->combineDateTime($run['date'], $run['time']),
            ], $course['runs']),
        ];
    }
    return $nodes ?: null;
}
```

The returned arrays are converted like map values (fields, images and lists inside are fine) and empty values
removed. `SchemaContext` also offers `recordId($record, $type)`, `formatDate($value)` and `imageObject($image)`.

To change a node without touching the map, implement `updateSchemaData(array &$node, SchemaContext $ctx)` in an
extension on the record class. To change the whole graph of a page, implement
`updateSeoSchemaGraph(SchemaGraph $graph, SchemaContext $ctx)` on the page.

### The organisation

The Organization node is mapped from SiteConfig with the same `schema_map`. The module ships a map using only
core fields and its own: `name` (the site title), `url` (the site root), `logo` and `sameAs` (Settings > SEO).
Extend it with the fields your site has:

```yml
SilverStripe\SiteConfig\SiteConfig:
  schema_type: EducationalOrganization
  schema_map:
    telephone: Phone
    email: Email
    sameAs: [SeoSchemaSameAs, FacebookURL, LinkedInURL]
    address:
      '@type': PostalAddress
      streetAddress: Street
      postalCode: PostalCode
      addressLocality: City
      addressCountry: { literal: NL }
```

Without a site title there is no Organization node, and the references to it are removed.

### Required properties and warnings

The `SchemaTypeRegistry` lists required and recommended properties per type, after Google's structured-data
documentation: Organization, LocalBusiness, WebSite, WebPage, BreadcrumbList, ItemList, Event (and its subtypes
such as EducationEvent), JobPosting, Course, CourseInstance, EducationalOccupationalProgram, Article (and
NewsArticle, BlogPosting), Place, PostalAddress, GeoCoordinates and ImageObject. Rich result types Google retired
(FAQ, HowTo, Course Info) are not offered.

A node that lacks a required property is **not output**, and references to it are removed; that can make
another node incomplete in turn (a JobPosting whose organisation was not output), which is then left out too.
Each skipped node is logged at debug level. A requirement can name alternatives: `jobLocation |
applicantLocationRequirements & jobLocationType` is met by a location, or by both properties of a remote job.

`$page->getSchemaWarnings()` lists, per finding, a `code`: `missing-required` (the node was left out),
`missing-recommended`, `method-not-allowed` (a map names something it may not call) and `type-not-registered`.
The page editor shows them, with the JSON the page would output (in the site's locale), under
**Search engines and sharing > Structured data**.

A type the registry does not know requires nothing, so its nodes are output, with a `type-not-registered`
warning. When you use a more specific type (a LocalBusiness subtype such as `Store`, say), register it with its
`parent` so the parent's requirements apply. Projects add types or properties:

```yml
Restruct\Silverstripe\SEO\Schema\SchemaTypeRegistry:
  types:
    Occupation:
      required: [name]
      recommended: [occupationalCategory, estimatedSalary]
    Event:
      recommended: [typicalAgeRange]
    Store:
      parent: LocalBusiness
```

## Extension hooks

Called on the page (implement them in an `Extension` applied to `SiteTree` or a page class):

| Hook | Use it to |
|---|---|
| `updateSeoTitle(string &$title)` | change the complete `<title>` text |
| `updateSeoDescriptionFallback(?string &$description)` | supply a better fallback description (also called on a shown record) |
| `updateSeoRobotsDirectives(array &$directives)` | add directives such as `max-image-preview:large` |
| `updateSeoCanonicalURL(?string &$url, ?HTTPRequest $request)` | change the canonical |
| `updateSeoImageFallback(?Image &$image)` | supply a sharing image when the page has none (a hero image, say) |
| `updateSeoMetaComponents(array &$tags)` | change any head tag this module produced, just before output (structured data is `structured-data`) |
| `updateSeoSchemaGraph(SchemaGraph $graph, SchemaContext $ctx)` | add or change structured-data nodes, before incomplete ones are left out |
| `updateSeoSchemaLocale(string &$locale)` | set the content locale of the structured data (`inLanguage`), for a translation module |

Called on a structured-data source record: `updateSchemaData(array &$node, SchemaContext $ctx)`, once per node.

The module itself uses core's `updateMetaComponents` hook. On SS6 the old `MetaTags` / `MetaComponents` extension
method names are no longer called, so a module still using them outputs nothing, silently.

## Migrating from other SEO modules

Columns were named to coincide with what other modules store, so the most common data is picked up by
`db:build` without any copying:

| Source module | Column | Here |
|---|---|---|
| kinglozzer/metatitle (also used by bigfork/silverstripe-simpleseo) | `MetaTitle` | `MetaTitle` (same column) |
| silverstripe-liveseo (older restruct module) | `MetaTitle` | `MetaTitle` (same column) |
| silverstripe-liveseo | `MetaRobotsNoIndex`, `MetaRobotsNoFollow`, `MetaRobotsNoSnippet` | same columns |
| silverstripe-liveseo | `MetaRobotsNoCache` | same column, output as `noarchive` |
| silverstripe-liveseo | `SEOFBdescription` | same column, the sharing description |
| hubertusanton/silverstripe-seo | `SEOSocialImageID` (has_one `SEOSocialImage`) | `OGImageID`: needs a copy, by the migration task (planned) |

**kinglozzer/metatitle** can stay installed while migrating: it adds a `MetaTitle` field of its own on the same
column, and this module then adds none (see `seo_meta_title_field_providers`, which lists that module's
extension by default). Its own `updateMetaTags` rewrites `<title>` from its `title_format` setting, though, so
with it installed `$MetaTags` prints that format rather than `$SeoTitle`. Use `$MetaTags(false)` with
`<title>$SeoTitle</title>`, or remove the module once the column is in use here.

Columns without a new home (meta keywords, page subject and score, Google+ and Facebook author or publisher links,
title templates) are left untouched in the database. A migration task with one mapper per source module, dry-run
by default, is planned; until then, copy columns by hand where needed.

<!-- Placeholder: per-module migration instructions follow with the migration task. -->

## Development

The tests need a Silverstripe 6 project with this module installed through a path repository (the CI workflow in
`.github/workflows/ci.yml` builds one). Add the test namespace to the host's `autoload-dev`, then:

```bash
SS_PHPUNIT_FLUSH=1 vendor/bin/phpunit vendor/restruct/silverstripe-seo/tests
```

The sitemap tests are skipped when wilr/silverstripe-googlesitemaps is not installed.

## Licence

MIT, see [LICENSE](LICENSE). Written from scratch; no code from other SEO modules is included.
