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
- **SEO checks** on the page as the site renders it (Elemental blocks and template text included): headings, alt
  text, links, duplicates, canonical, structured data and more, as a panel in the page editor with counts per
  severity (no score), how to fix each finding, and a search result preview that updates while you type.
- **Site report** in Reports: every page with its counts per severity, last checked, out-of-date marker,
  filters and a CSV export, and a summary ("pages without problems: N of M indexable pages"). A queued job
  (with [symbiote/silverstripe-queuedjobs](https://github.com/symbiote/silverstripe-queuedjobs)) or a sake task
  checks the whole site.
- **Sitemap bridge**: with [wilr/silverstripe-googlesitemaps](https://github.com/wilr/silverstripe-googlesitemaps)
  installed, noindex pages are left out of `sitemap.xml`.

Planned for later versions: optional AI suggestions.

## Requirements

- PHP 8.3+
- Silverstripe CMS 6 (`silverstripe/framework` ^6, `silverstripe/cms` ^6)
- [restruct/silverstripe-rendered-content-extractor](https://github.com/restruct/silverstripe-rendered-content-extractor)
  (installed with it): renders a page in-process for the checks

## Installation

```bash
composer require restruct/silverstripe-seo
vendor/bin/sake db:build --flush
```

The fields appear in the page editor on a tab of their own, **SEO**, right after the Main tab: at the top the
SEO checks panel, then the search result preview, then the fields (meta title, meta description, search engine
indexing, canonical URL, sharing, and core's free-form custom meta tags last). Core's own MetaDescription and
ExtraMeta move there too, and core's **Metadata** toggle below the content goes when that leaves it empty. The
site-wide defaults are under **Settings > SEO**.

```yml
SilverStripe\CMS\Model\SiteTree:
  # Another tab (created after Main when it does not exist); meant to be a tab of its own
  seo_tab: 'Root.SEO'
  # Keep core's Metadata toggle even when empty: for a page class that looks it up after parent::getCMSFields()
  seo_remove_empty_metadata_toggle: true
```

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

The response headers (`X-Robots-Tag`, `Link`) need nothing from the theme. A header your own controller code sets
(in `init()`, before or after `parent::init()`, or in an action, on the controller's response or on one the
action returns) is never overwritten, and one an action removes is not put back: project code decides.

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
- **Organisation contact details**: description, email address, telephone, and a postal address (street and
  number, postcode, place, province or region, country as a code such as `NL` or a name). The Organization
  node's `description`, `email`, `telephone` and `address` (see [The organisation](#the-organisation)). Other
  code that needs the organisation's details (a partner feed, a contact block) can read them from SiteConfig
  too: `SeoOrgDescription`, `SeoOrgEmail`, `SeoOrgTelephone`, `SeoOrgStreet`, `SeoOrgPostalCode`,
  `SeoOrgLocality`, `SeoOrgRegion`, `SeoOrgCountry`.

The sharing image of a page is, in this order: the page's own (**Sharing image**), the first usable image of
`seo_image_fallback`, what the `updateSeoImageFallback` hook makes of that, and the site default.
`seo_image_fallback` (empty by default) lists relations or methods of the page type, tried in order: a has_one
image, a has_many or many_many of images (its first usable image), or a method returning an image or a list:

```yml
App\Page\CoursePage:
  seo_image_fallback:
    - HeaderImage
    - Gallery
```

A name the page does not have, a value that is not an image (a File relation), an empty relation and an image
that is not public are passed over. The hook receives the image the config found (or null) and may replace it,
so a hook-based fallback keeps winning. A record shown on a sub-action does not use the page's relations.

A sharing image is fetched anonymously by the sites a page is shared on, so only a public image is used: one
that is published, in the public asset store and viewable by anyone. A draft-only image, or one restricted to
logged-in members, is skipped and the next one in the chain is used.

### Hiding or locking fields per page class

A page type whose title or robots are decided by code (a course page that generates its title, say) can leave
the module's fields out of its edit form, or show them read-only:

```yml
App\Page\CoursePage:
  # Not in the form at all; a stored value stays and is still read
  seo_hidden_fields:
    - MetaTitle
  # Shown, but not editable
  seo_readonly_fields:
    - MetaRobotsNoIndex
```

The names are the field names: `MetaTitle`, `MetaDescription` (core's, on the same tab), `MetaRobotsNoIndex`,
`MetaRobotsNoFollow`, `MetaRobotsNoSnippet`, `MetaRobotsNoCache`, `CanonicalURL`, `SEOFBdescription` and
`OGImage`. Other names are ignored. Both are lists, so a subclass adds to its parent's; a field in both is hidden.
What the page outputs is not changed by this: a page that generates its title does so with a getter (see
[Titles, robots and descriptions from code](#titles-robots-and-descriptions-from-code)).

### Titles, robots and descriptions from code

The values are read the way a template reads them, so a page class that decides them in code is respected
everywhere the module uses them: the head, the `X-Robots-Tag` header, the sitemap, the search result preview and
the checks (the noindex check and which checks run, the title checks, and the duplicate checks on both sides).

```php
class CoursePage extends Page
{
    # The title is generated; whatever is in the MetaTitle column is ignored
    public function getMetaTitle(): string
    {
        return $this->Title . ' (' . $this->CourseCode . ')';
    }

    # Archived courses are never indexed, whatever the checkbox says
    public function getMetaRobotsNoIndex(): bool
    {
        return (bool) $this->Archived || (bool) $this->getField('MetaRobotsNoIndex');
    }

    # The description when the editor typed none, before the module derives one from the content
    public function MetaDescriptionFallback(): string
    {
        return sprintf('%s: dates, prices and locations.', $this->Title);
    }
}
```

- A `get<Field>()` override wins over the column for `MetaTitle` and the robots flags (`MetaRobotsNoIndex`,
  `MetaRobotsNoFollow`, `MetaRobotsNoSnippet`, `MetaRobotsNoCache`).
- `MetaDescriptionFallback()` is a convention of this module: when the page has it and it returns text, that is
  the fallback description; when it returns an empty string, the module derives one from the content as usual.
  The `updateSeoDescriptionFallback` hook runs after either, and `seo_description_fallback: false` switches both
  off. A typed meta description always wins.
- The duplicate-title check loads every page of a class with a `getMetaTitle()` override to compare its title,
  since that title exists only in PHP. It does so once per check run of a page, and once per run of
  `tasks:seo-check-site` or per step of `SeoSiteCheckJob` (all pages of that run share the computed titles), so
  a getter that reads a relation costs its queries once per run, not once per page checked.
- Hide or lock the field the getter makes meaningless with `seo_hidden_fields` / `seo_readonly_fields` (above);
  the search result preview then shows the title from PHP rather than from the form.

## Canonical URLs

- A page's canonical is its own URL, without query string except the parameters in
  `seo_canonical_query_params`.
- An action URL is its own canonical: everything after the page's path is kept (`/blog/archive/2024/05/12` for
  a url_handler `archive/$Year!/$Month/$Day`), with the action segment lowercased. On the homepage the `home`
  segment is kept (`/home/archive/...`), since that is where the action lives.
- Error pages, error responses (status 400 or higher) and redirects (including redirector pages) get no
  canonical, no `og:url` and no `Link` header; redirects also get no `X-Robots-Tag`.
- The site root is written with its slash (`https://example.com/`), also for an override of `/`. Core's
  `Director::absoluteURL()` leaves it without one when `add_trailing_slash` is off; both are the same URL.
- A project that overrides `MetaComponents()` (or implements `updateMetaComponents`) and sets
  `$tags['canonical']` after calling the parent replaces the module's canonical tag, and the canonical check
  then judges the project's value, not the module's. The check compares the canonical with the page's own URL
  ignoring the case of the host and a trailing slash, but not the scheme: an `http://` canonical on an https
  site fails.

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

The [structured data](#structured-data) follows the same switch:

- **Without `setSeoSubject()`** the sub-action (`/courses/item/12`) still gets a WebPage of its own URL, but it
  describes the holder page: the holder's title and description, the holder's own node or ItemList as its
  `mainEntity`, and a breadcrumb that ends at the holder.
- **With `setSeoSubject($record)`** the WebPage describes the record's URL with the record's title, the
  record's node is its `mainEntity` (when the record's class is a [source](#records-as-sources) and its node is
  complete), and the breadcrumb ends at the record. The holder's own node and ItemList are left out there.

A project that already prints its own JSON-LD for the record (a JobPosting in the template, say) can keep it:
it is a separate `<script>` next to the module's graph, and nothing in the module reads or removes it. To get
one linked graph instead (the record's node pointing at the site's Organization by `@id`, and the WebPage
pointing at the record), move it to the module: map the record in YAML (`schema_map`) or give it a
`provideSchemaData()` method, and remove the template's own script, so the record is not described twice.

A canonical the project sets itself is left alone. A `MetaComponents()` override on the page class that
replaces `$tags['canonical']` after calling the parent wins over the module's tag, and a `Link` header the
controller sets (in `init()` or in the action) is never overwritten or put back. Either one changes only that
output: the module's own `Link` header, `og:url` and the structured-data `@id`s still use the module's canonical.
To change the canonical everywhere at once, use the `updateSeoCanonicalURL` hook (see
[Extension hooks](#extension-hooks)).

The record is read by duck-typing, so most DataObjects need no changes: `MetaTitle` or `Title`; `MetaDescription`
or the first paragraph of `Content`; `SEOFBdescription` for sharing; `AbsoluteLink()` or `Link()` for the
canonical, with tracking parameters (`utm_*`, `gclid`, `fbclid`, ...) removed; an `OGImage` has_one or a
`getSeoImage()` method; and the `MetaRobots*` boolean fields or getters if it has them (otherwise the page's
robots apply). Values are read as a template reads them, so a getter override (`getMetaTitle()`,
`getMetaRobotsNoIndex()`) wins over the column, and a getter without a column counts as a setting of its own.
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
  a list of nodes, ordered by `sort` and capped by `limit`, see below), and `'@required'`: a list of properties it must have in the registry's notation
  (`streetAddress | addressLocality` for either), without which the nested node is left out;
- a YAML list of any of these: a list of values (`sameAs: [FacebookURL, LinkedInURL]`).

A nested map over a list takes `limit` (at most that many nodes) and `sort` (as `DataList::sort()` takes it, a
string or `{ Field: ASC }`). A course with many runs, the next fifty as CourseInstances:

```yml
App\Page\CoursePage:
  extensions:
    - Restruct\Silverstripe\SEO\Extensions\SchemaSource
  schema_type: Course
  schema_map:
    name: Title
    description: Summary
    provider: { ref: organization }
    hasCourseInstance:
      '@type': CourseInstance
      from: Runs
      sort: 'StartDate ASC'
      limit: 50
      courseMode: Mode
      startDate: { date: StartDate, time: StartTime }
      location:
        '@type': Place
        from: Venue
        name: Title
        address:
          '@type': PostalAddress
          streetAddress: Street
          addressLocality: City
      offers:
        '@type': Offer
        price: Cost
        priceCurrency: { literal: EUR }
```

The limit counts the nodes that are output: a run an anonymous visitor may not see, or one that maps to nothing,
does not use up a place. A sort the list cannot apply (an unknown column, a plain array from a method) is
ignored. `limit` and `sort` are not output as properties.

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
core fields and its own: `name` (the site title), `url` (the site root), and from Settings > SEO `logo`,
`sameAs`, `description`, `email`, `telephone` and `address`. The address is a nested PostalAddress that is only
output when it has a street or a place (`'@required': ['streetAddress | addressLocality']`): a postcode or a
country alone locates nothing. Point a property at a field your site already has, or extend the map:

```yml
SilverStripe\SiteConfig\SiteConfig:
  schema_type: EducationalOrganization
  schema_map:
    telephone: Phone
    email: Email
    sameAs: [SeoSchemaSameAs, FacebookURL, LinkedInURL]
    address:
      addressCountry: { literal: NL }
```

Config arrays merge, so a project's `address` map changes the entries it names and keeps the others (and the
`@required` guard); `address: null` drops the address.

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
**SEO > Structured data**.

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

## SEO checks

The checks run on the **draft as the site renders it**: the page is rendered in-process through its own
controller and templates (no HTTP request, so drafts, basic auth and test environments all work), as the CMS
user who asks, by [restruct/silverstripe-rendered-content-extractor](https://github.com/restruct/silverstripe-rendered-content-extractor).
The render is parsed once and shared by all checks; content checks read the content area (by default the visible
`<main>`, else `[role=main]`, else `<body>`; configurable per site and per page type with
`ContentNarrower.custom_content_selectors` (tried first, the YAML way to name your own container),
`ContentNarrower.content_selectors` and its `updateContentSelectors` hook), minus what
`ContentNarrower.ignore_selectors` and `data-content-ignore` mark; head checks read the full head.

In the page editor, at the top of the **SEO** tab:

- **SEO checks**: "1 problem, 2 warnings, 3 tips", then the failing checks per group (indexing, title,
  description, content, images, links, structured data, social, URL), each with its priority, the message,
  details (the images or links concerned) and how to fix it. An image is named by its file, linked to its
  edit form in the Files admin, also when the page shows a resized or converted variant; an image that is no
  file of this site keeps its URL. There is no score: severity says how bad it
  is (problem / warning / tip), priority how much it matters (high / medium / low, an outlined badge).
  A failing check is headed by what was found ("Title is not unique") next to its severity badge; the passed
  checks fold away under their count, each headed by what is right ("Title is unique") and marked **OK**; checks
  that could not run are marked **Not checked**.
- **About this check**: the info icon after a check's title opens a dialog inside the CMS, rendered on the server
  by `admin/seo-checks/doc?check=<id>`: what the check looks at and why it matters, in the editor's language, and
  the check's how-to-fix advice. Admins also get the check's spec (`docs/checks/<id>.md`, English) folded under
  "For developers". Without JavaScript, and for a project's own check, the icon is a link to the published spec.
- **Ignore for this page**: the icon button at the top right of a finding ignores a check where the page is fine
  as it is; it is stored with who and when and not counted. Ignored checks are listed in the folded list of
  passed checks ("18 checks OK, 2 ignored"), marked **Ignored**, each with an icon button to check it again.
- **Search result preview** below the panel, above the fields: title, URL breadcrumb and description, with the title's
  approximate width and the description's length, updated on every keystroke. The numbers are the checks' own
  thresholds, passed from PHP config, so the counter and the checks agree.

A page that asks not to be indexed gets only the "published but noindex: intentional?" check. Redirector, virtual
and error pages are not checked (`PageEligibility.excluded_classes` of the render library).

### When the checks run

Results are stored per page (`SeoCheckResult`: page, stage, checked at, the results as JSON, counts per
severity). **Nothing is rendered while a page is saved**: a save marks the stored result stale, and the panel's
script then asks `admin/seo-checks/recheck` for a fresh check in a request of its own, once the form has
reloaded; it also re-checks after every save or publish (a save that only changed blocks does not write the
page) and on the **Re-check** button. Without JavaScript the panel still shows the stored results, marked as out
of date when they are.

The endpoint is POST only, needs the CMS's CSRF token and CMS access, checks `canEdit()` on the page (the ID is
the edit form's own) and answers with the results and the panel's markup, never with the rendered page.
The `doc` action only reads: it needs CMS access but no rights on a page, accepts only the id of a registered
module check (never a path; anything else is a 404), and renders the spec with a small Markdown subset that
escapes all text, so no raw HTML of the spec reaches the CMS (`Restruct\Silverstripe\SEO\Text\DocMarkdown`).
The dialog and the tooltips are the admin's own Bootstrap 5: the admin loads its tooltip, and the module adds the
admin's copy of its modal (`thirdparty/bootstrap/js/dist/modal.js`) to the CMS requirements.
Once authorised it releases the PHP session lock, so a slow render does not block the editor's other CMS
requests. The panel is only shown to members who may edit the page; the stored result is shared between them
and reflects the render of whoever checked last. Messages are stored as translation keys and shown in the
reader's language. Block writes, publishes and deletes (Elemental) mark the page's result stale too, and a
result older than the last edit of the page or one of its blocks counts as stale.

Two changes elsewhere also make a result stale:

- **Another page changed.** The checks that read other pages (`title-duplicate`, `description-duplicate`,
  `broken-links`, `unpublished-links`, `link-redirects`, `orphan-page`; a check says so with `cross_page: true`)
  can be wrong once any page changed. A result that holds one of them is stale when any page was edited after
  it was checked. Since most pages run `broken-links`, an edit anywhere makes most results stale: the panel
  re-checks on the next open, and a site run with only stale results checks most pages again.
- **The checks changed.** Every result stores a fingerprint of the check configuration: the enabled checks,
  their classes and config (thresholds, severity, priority), `CheckRunner::CHECKS_VERSION`, which a release
  raises when what a check finds changes, and the installed version of the module, so every result is checked
  again after an update. A result checked under another fingerprint is stale.
- **The page did not render.** A result whose render failed (a redirect, an error) is stale: the cause may be
  gone, so the panel checks again when it opens instead of showing the old failure.

When the panel cannot start a check (no endpoint URL, page ID or security token in the form) or the request
fails, it says so next to the **Re-check** button ("Could not start the check.", "Check failed (HTTP 403).",
with the server's reason when it gave one) and logs the details to the browser console.

**Rendering has side effects** that a page's own code can cause: the render runs the page's controller and
template in the same PHP process (see the render library's README). Code that should not run during a check
(analytics, counters, mail) can test
`Restruct\Silverstripe\RenderedContentExtractor\PageRenderer::isRendering()`.

### The catalogue

| Check | Finds | Severity / priority |
|---|---|---|
| `noindex-published` | a published page set to noindex | problem / high |
| `title-missing` | no `<title>`, or an empty one | problem / high |
| `title-boilerplate` | the title is the site name, has it twice, or is a placeholder ("New Page") | warning / high |
| `title-duplicate` | another indexable page with the same title | warning / high |
| `title-width` | a title wider than about 600 px (estimated per character) | tip / medium |
| `description-missing` | no description typed and a weak fallback (suggests one from the rendered page) | warning / medium |
| `description-duplicate` | another indexable page with the same typed description | warning / medium |
| `description-length` | a description under 70 or over 160 characters | tip / low |
| `h1-missing` | no H1 in the rendered page | warning / medium |
| `heading-structure` | more than one H1, or skipped heading levels in the content | tip / low |
| `image-alt-missing` | images without an alt attribute (`alt=""` counts as decorative) | warning / high |
| `image-alt-quality` | alt text that is a file name, "image of...", too short or long, duplicated, or stuffed | tip / medium |
| `broken-links` | links and images to pages and files that do not exist (database only, no HTTP) | problem / high |
| `unpublished-links` | links and images to pages and files that exist only as a draft | warning / medium |
| `link-text` | links without text, or "click here" / "lees meer" | tip / medium |
| `link-redirects` | links to redirector pages, http:// links on an https site | tip / low |
| `orphan-page` | not in the menus and no inbound links from other pages or blocks | warning / medium |
| `canonical` | a canonical pointing elsewhere, invalid, or given twice | warning / high |
| `placeholder-text` | lorem ipsum, TODO, xxx in the content, title or description | problem / high |
| `thin-content` | very little text (information only, no word-count target) | tip / low |
| `structured-data` | invalid JSON-LD, or nodes missing required properties | problem / medium |
| `og-image` | no sharing image and no site default | tip / low |
| `url-segment` | underscores, a long segment, many small words | tip / low |

Each check has a spec in [docs/checks](docs/checks) (what, why, thresholds, source, last reviewed), and
[docs/dropped-checks.md](docs/dropped-checks.md) lists what is left out on purpose (focus keyword, keyword
density, word-count targets, readability scores, ...) and why.

### Configuration

```yaml
# Switch a check off, or add your own (a class implementing Check, usually extending AbstractCheck)
Restruct\Silverstripe\SEO\Checks\CheckRunner:
  checks:
    thin-content:
      enabled: false
    my-check:
      class: App\Seo\MyCheck

# Thresholds, severity and priority are config of each check class
Restruct\Silverstripe\SEO\Checks\Rules\DescriptionLengthCheck:
  min_length: 80
  max_length: 155
Restruct\Silverstripe\SEO\Checks\Rules\LinkTextCheck:
  non_descriptive_texts:
    de: ['hier klicken', 'mehr']
Restruct\Silverstripe\SEO\Checks\Rules\OrphanPageCheck:
  severity: tip
```

A check gets a `PageAnalysis`: the draft record, the `RenderResult`, `content()` (the narrowed DOM, with
`query()` for XPath), `head()`, `title()`, `description()`, `links()` (resolves an internal URL to a page or file
from the database) and `site()` (duplicate lookups, inbound links, menu placement). Set `needs_render: true` on a
check that reads the render; it is then skipped, not failed, when the page does not render.

A check has three titles: `title()`, its goal ("Title is unique", shown for a skipped check and in the report's
check filter), `passTitle()` (defaults to the goal; override it when the goal is a neutral name such as
"Canonical URL") and `failTitle()`, what a failure means ("Title is not unique"). Give every check a
`failTitle()`: without one a failure is headed by the goal and reads like a pass.

Build messages with `$this->_t(key, default, params)` rather than the global `_t()`: they are stored as key and
parameters and translated when shown. Details are capped at `max_listed` (default 20, config on
`AbstractCheck` or per check class), followed by "and N more". "About this check" links to this module's docs
only for the module's own checks; a project's check links to whatever its `docsUrl()` returns (nothing by
default), and only the module's own checks open in the CMS dialog. A check explains itself to editors in that
dialog with `about()` (empty by default on `AbstractCheck`). `OrphanPageCheck.lists_children_classes` names holder classes whose template lists their children
(empty by default; a blog or news holder, say); an `updateIsOrphan(bool &$orphan, PageAnalysis $analysis)`
extension hook decides other cases.

Internal links are resolved from the database and the public folder, without HTTP requests: pages (an action
after the page's path is accepted), files under assets (variants and converted variants included, via core's
file ID parsing), static files in the public folder (`_resources`, favicon.ico) and the old URLs of renamed
pages (reported as a redirect). Every srcset candidate counts as a link.

**Fluent and other translation modules: not supported yet.** A URL with a locale prefix (`/nl/...`) is not
resolved and shows up as broken. A bridge can resolve it in an extension on
`Restruct\Silverstripe\SEO\Checks\LinkResolver` implementing
`updateResolveInternalLink(array &$result, string $url, string $relative)`.

The inbound-link graph comes from core's link tracking (`SiteTreeLink`, which Elemental blocks feed as well) and
is cached site-wide (`Psr\SimpleCache\CacheInterface.SeoLinkGraph`), cleared when a page is written, published,
unpublished or archived. Links printed by templates (a footer) are not in it; ignore the orphan check for pages
that are linked that way.

## Site report

**Reports > SEO status** lists every page the checks cover (the same eligibility as the panel), read from the
stored results; the report itself renders nothing.

- Per page: problems, warnings and tips (ignored checks not counted), when it was last checked, the status (up to
  date, out of date, not checked), whether it is indexable, and which checks fail.
- **Summary** at the top: "Pages without problems: N of M indexable pages" (a noindex page is not indexable; a
  page not checked yet counts towards M, not N), how many pages are not checked or out of date, and the checks
  that fail on most pages the reader may edit. There is no score.
- **Filters**: severity (with problems / warnings / tips, or checked without problems), a failing check, page
  type, status. The list sorts on every column.
- **CSV export** of the filtered list (every page, not one page of the list), with the edit link. Every cell
  that starts with `=`, `+`, `-`, `@`, a tab or a carriage return gets a leading `'`, so a spreadsheet does not
  run a page title as a formula. Print works as for any report.

**Who sees what.** The report needs the permission **View the SEO status report** (`VIEW_SEO_REPORT`, group
settings, category SEO) plus access to the Reports section (`CMS_ACCESS_ReportAdmin`); administrators have both.
It lists only pages the reader may view. A reader without draft access sees a page with unpublished changes
by its published title, and "Not available without draft access" instead of its counts, indexing, type and
status, which come from the draft (such rows are left out of the summary). Which checks fail, the check filter,
the top failing checks in the summary and the link to edit the page are only there for pages the reader may
edit; for other pages the reader sees the counts.

**Cost.** Every view of the report reads all eligible pages and their stored results, with a fixed number of
queries: the staleness of every row in three, view and edit rights through core's batched permission and
version caches, and the results JSON only for pages whose failing checks the reader sees. Measured on the test
host (MariaDB, one view building the summary, the filters and the list): 200 pages took about 0.15 s and 35
queries, 1000 pages about 0.65 s and the same 35 queries. Time grows with the number of pages (each page
object is loaded and checked for noindex); fine for hundreds to a few thousand pages.

It is a report rather than a CMS section of its own because silverstripe/reports comes with silverstripe/cms and
already brings the filter form, the sortable and paginated list, print and export.

### Checking the whole site

The panel checks a page when an editor opens it. To fill and refresh the report for every page:

- **With queuedjobs**: queue `Restruct\Silverstripe\SEO\Jobs\SeoSiteCheckJob` (in the Jobs admin, or from code
  with `QueuedJobService::singleton()->queueJob(new SeoSiteCheckJob())`). It checks a batch of pages per step
  (`SeoSiteCheckJob.batch_size`, 10), keeps the pages still to do in its job data so a paused or restarted job
  carries on, and skips pages whose result is up to date (`new SeoSiteCheckJob(false)` checks every page). It
  runs on the large queue (`SeoSiteCheckJob.queue`), so that queue needs its runner. To check nightly, add it to
  `QueuedJobService.defaultJobs` (an Injector property) or queue it from a cron.
- **Without queuedjobs**: `vendor/bin/sake tasks:seo-check-site` (CLI only), with `--only-stale` to skip
  up-to-date results, `--limit=N` to stop after checking N pages (skipped and failed pages do not count; the task
  then prints the ID to go on from) and `--from-id=N` to start at that page ID, so a cron can work through a
  large site a slice at a time.

**Who the site check renders as.** Not as the member who queued the job or ran the task (the queue runs a job as
whoever queued it). By default it renders as a **visitor**, with the draft readable for the duration of each
check: a render as an editor would store what that editor sees (content for their groups, CMS-only markup) in a
result every other editor reads. The result is the draft as the public site will show it once published. A page
visitors may not see (only logged-in users, or groups) is therefore not checked by the site run, and is counted
as "not visible"; the panel still checks it as its editor. A site whose pages are behind a login sets a
dedicated member with view and draft rights:

```yaml
Restruct\Silverstripe\SEO\Report\SiteCheck:
  run_as: 'seo-checks@example.com'
```

A `run_as` that names no member stops the run, rather than silently checking as a visitor.

**A site run renders every page.** Each check runs the page's controller and templates in-process, so code with
side effects (view counters, mail, calls to outside services, writes) runs once per page unless it tests
`Restruct\Silverstripe\RenderedContentExtractor\PageRenderer::isRendering()`. One page that throws is logged and
counted as failed; the run carries on.

## Extension hooks

Called on the page (implement them in an `Extension` applied to `SiteTree` or a page class):

| Hook | Use it to |
|---|---|
| `updateSeoTitle(string &$title)` | change the complete `<title>` text |
| `updateSeoDescriptionFallback(?string &$description)` | supply a better fallback description (also called on a shown record); a page can also define `MetaDescriptionFallback()`, see [Titles, robots and descriptions from code](#titles-robots-and-descriptions-from-code) |
| `updateSeoRobotsDirectives(array &$directives)` | add directives such as `max-image-preview:large` |
| `updateSeoCanonicalURL(?string &$url, ?HTTPRequest $request)` | change the canonical |
| `updateSeoImageFallback(?Image &$image)` | supply a sharing image when the page has none (a hero image, say); receives the `seo_image_fallback` image or null, and may replace it |
| `updateSeoMetaComponents(array &$tags)` | change any head tag this module produced, just before output (structured data is `structured-data`) |
| `updateSeoSchemaGraph(SchemaGraph $graph, SchemaContext $ctx)` | add or change structured-data nodes, before incomplete ones are left out |
| `updateSeoSchemaLocale(string &$locale)` | set the content locale of the structured data (`inLanguage`), for a translation module |

Called on a structured-data source record: `updateSchemaData(array &$node, SchemaContext $ctx)`, once per node.

The module itself uses core's `updateMetaComponents` hook. On SS6 the old `MetaTags` / `MetaComponents` extension
method names are no longer called, so a module still using them outputs nothing, silently.

## Migrating from another SEO module

Columns were named to coincide with what other modules store, so the most common data is picked up by
`db:build` without any copying (`MetaTitle` is shared by kinglozzer/metatitle and the older liveseo module).
The rest is copied by a task, with one mapper per source module:

```bash
vendor/bin/sake tasks:seo-migrate --source=hubertusanton            # dry run: reports, writes nothing
vendor/bin/sake tasks:seo-migrate --source=hubertusanton --apply    # writes
```

Sources: `liveseo`, `hubertusanton`, `plasticstudio`, `simpleseo`, `jellygnite`. Options:

- `--apply` writes, in one transaction that any error rolls back; without it the task is a dry run
  (`--dry-run` says so explicitly; giving both is refused).
- `--overwrite` replaces values already filled in here. Without it, a filled-in value is never changed: it is
  counted as a conflict and kept.

The report has one line per column and stage: rows read, copied, already the same, conflicts, and invalid
(a value that cannot be used here, such as an image that no longer exists, a malformed URL, or a value longer
than the column here holds: it is never cut off). An image that exists but is not published is copied to the
draft only and counted as unpublished on the live line. Columns without
a home here are listed with the number of rows that have a value and, where there is one, what to do instead.
Old columns that are not in the database are skipped with a notice.

How it writes:

- **Per stage.** Pages are versioned. The draft table gets the old draft value and the `_Live` table the old
  live value, with plain SQL. Nothing is published and no new version is written, so a page with unpublished
  changes keeps them unpublished, and a published page does not show up as modified. The page history is not
  changed: rolling a page back to a version from before the migration gives that version's empty column.
- **Idempotent.** A target that already holds the same value counts as "already same", so a second run copies
  nothing.
- **Old data is left alone.** Old columns and tables are read, never changed or dropped. When `db:build` has
  renamed an old table to `_obsolete_<table>` (it does so once no class gives the table a field any more, e.g.
  `Page` after removing a module that extended `Page`), the task reads from that table instead. A second rename
  adds a number (`_obsolete_Page2`, `_obsolete_Page_Live2`): the highest number holding the column is used,
  with a warning, and the live table is found by its own name.
- **Several old columns for one target** (a Facebook image, else a Twitter one): a value that cannot be used
  gives way to the next column.
- Writing with plain SQL bypasses caches: flush, and regenerate a static cache, after applying.
- **Not for Fluent yet.** With a `SiteTree_Localised` or `SiteConfig_Localised` table, values live per locale,
  which the task does not migrate: a dry run reports with a warning, and `--apply` is refused. Copy the
  localised values by hand.

### Recommended order

1. `composer require restruct/silverstripe-seo` with the old module still installed, then
   `vendor/bin/sake db:build --flush`.
2. Dry run: `vendor/bin/sake tasks:seo-migrate --source=<name>`. Read the report: conflicts, invalid values,
   and the columns without a home.
3. Apply: the same with `--apply`. A second run should copy nothing.
4. Check some pages: the head output, and the fields on the **SEO** tab.
5. `composer remove` the old module, then `vendor/bin/sake db:build --flush`. Its columns stay in the database;
   drop them by hand once you no longer need them. Remove any project class that extends or is injected for the
   old module's classes (e.g. an extension subclass registered via Injector) together with the old package, or
   `db:build` fails on the missing parent class.

### Per source module

**The older liveseo module** (`--source=liveseo`):

| Old column | Here |
|---|---|
| `MetaTitle`, `MetaRobotsNoIndex`, `MetaRobotsNoFollow`, `MetaRobotsNoSnippet`, `SEOFBdescription` | same columns, nothing to copy |
| `MetaRobotsNoCache` | same column, output as `noarchive` |
| `MetaKeywords`, `SEOPageSubject`, `SEOPageScore` | dropped |
| `SEOFBPublisherlink`, `SEOFBAuthorlink`, `SEOGplusAuthorlink`, `SEOGplusPublisherlink` | dropped |
| SiteConfig `SEOTitleTemplate` | dropped: the title is the meta title, the separator and `SeoTitleSuffix` or the site name |
| StructuredData tables | reported: rows per type and the number of pages they are attached to |
| the site organisation's first `logo` | SiteConfig `SeoLogoID` |
| the site organisation's `sameAs` (MultiValueField, serialised PHP or JSON) | SiteConfig `SeoSameAs`, one URL per line |
| the organisation's `name` | reported next to SiteConfig `Title`, which the Organization node uses |
| the organisation's `description`, `email`, `telephone` | SiteConfig `SeoOrgDescription`, `SeoOrgEmail`, `SeoOrgTelephone` |
| its one `address` (StructuredDataPostalAddress `streetAddress`, `postalCode`, `addressLocality`, `addressCountry`) | SiteConfig `SeoOrgStreet`, `SeoOrgPostalCode`, `SeoOrgLocality`, `SeoOrgCountry`; with two or more addresses nothing is copied and the task says so |
| `faxNumber`, `contactPoint`, `department`, `location`, other LocalBusiness rows (branches) | reported: no fields here; add your own and map them in YAML |
| `Rating`, `AggregateRating` | dropped |

The site organisation is the one Organization or LocalBusiness that is not a department or location of another.
With more than one, or with more than one SiteConfig row (subsites), nothing is copied and the task says so.
Code of the project that reads the old organisation (a partner feed, a template) has to read SiteConfig's
`SeoOrg*` fields and `Title` instead before the old module is removed; the report reminds of it.
**Check the page source first:** a site's live Organization JSON-LD may come from a hard-coded template rather
than from these tables.

**hubertusanton/silverstripe-seo** (`--source=hubertusanton`, columns on `Page`):

| Old column | Here |
|---|---|
| `SEOSocialImageID` (has_one `SEOSocialImage`) | `OGImageID` |
| `SEOHideSocialData` | reported: no per-page switch; `seo_output_open_graph: false` turns Open Graph off site-wide |
| `SEOPageSubject`, `SEOSocialType` | dropped |
| SiteConfig `GoogleWebmasterMetaTag` | reported: put the verification tag in the template, or verify through DNS |

**plasticstudio/silverstripe-seo** (`--source=plasticstudio`, columns on `Page`; also for
cyber-duck/silverstripe-seo and its forks, which share the schema):

| Old column | Here |
|---|---|
| `MetaTitle` | `MetaTitle` (Varchar(512) there, 255 here: a longer value is reported as invalid) |
| `Canonical` | `CanonicalURL` |
| `Robots` (`noindex,nofollow`, `none`, ...) | `MetaRobotsNoIndex`, `MetaRobotsNoFollow`, `MetaRobotsNoSnippet`, `MetaRobotsNoCache` (`noarchive`); a flag is only ever set, never cleared |
| `SocialImageID` | `OGImageID` |
| `Priority`, `ChangeFrequency` | reported: wilr/silverstripe-googlesitemaps has its own |
| `SitemapHide`, `XMLSitemapHide`, `HideSocial`, `OGtype`, `TwitterCard`, `ManualSchema`, `SeoHeadTag` rows | reported, with what to use instead |
| `OGlocale`, `MetaTitleLastEdited`, `MetaDescriptionLastEdited` | dropped |
| SiteConfig `DefaultSocialImageID` | `SeoDefaultImageID` |
| SiteConfig `SchemaOrganisationImageID` | `SeoLogoID` |
| SiteConfig `SchemaOrganisationName`, address fields, `Phone` | reported |
| SiteConfig `OGSiteName`, `TwitterHandle`, `CreatorTwitterHandle`, `FacebookAppID`, `UseTitleAsMetaTitle` | dropped |

It has no Open Graph or Twitter text columns, so the sharing description falls back to the meta description.

**bigfork/silverstripe-simpleseo** with kinglozzer/metatitle (`--source=simpleseo`):

| Old column | Here |
|---|---|
| `MetaTitle` | same column, nothing to copy |
| `CanonicalLinkID` (a silverstripe/linkfield Link) | `CanonicalURL`, resolved per stage: a page link root-relative (with its query string and anchor), a file's URL, an external URL as entered; a link to the page itself is the default already; email and phone links are invalid |
| `ExtraMeta` | core's own field, nothing to do |

Page and file links are stored root-relative on purpose, so they do not carry the host name of wherever the task
ran; the module makes them absolute on output. The Link tables must still exist (silverstripe/linkfield
installed, or its tables left in place).

**jellygnite/silverstripe-seo** (`--source=jellygnite`, a Silverstripe 5 module, columns on `Page`):

| Old column | Here |
|---|---|
| `MetaTitle`, `CanonicalURL` | the same names on `SiteTree` |
| `FacebookPageDescription`, else `TwitterPageDescription` | `SEOFBdescription` |
| `FacebookPageImageID`, else `TwitterPageImageID` | `OGImageID` |
| `FacebookPageTitle`, `TwitterPageTitle`, `FacebookPageType` | reported: og:title is the meta title here |
| `FocusKeyword`, `CreatorID` | dropped |
| SiteConfig tracking fields, `TwitterAccountName` | reported or dropped |

A project can add a mapper for another module: implement `SeoMigrationMapper` and register it under a source
name in `Restruct\Silverstripe\SEO\Tasks\MigrateFromSeoModuleTask.mappers`.

**kinglozzer/metatitle** can stay installed while migrating: it adds a `MetaTitle` field of its own on the same
column, and this module then adds none (see `seo_meta_title_field_providers`, which lists that module's
extension by default). Its own `updateMetaTags` rewrites `<title>` from its `title_format` setting, though, so
with it installed `$MetaTags` prints that format rather than `$SeoTitle`. Use `$MetaTags(false)` with
`<title>$SeoTitle</title>`, or remove the module once the column is in use here.

## Development

The tests need a Silverstripe 6 project with this module installed through a path repository (the CI workflow in
`.github/workflows/ci.yml` builds one). Add the test namespace to the host's `autoload-dev`, then:

```bash
SS_PHPUNIT_FLUSH=1 vendor/bin/phpunit vendor/restruct/silverstripe-seo/tests
```

The sitemap tests are skipped when wilr/silverstripe-googlesitemaps is not installed.

## Licence

MIT, see [LICENSE](LICENSE). Written from scratch; no code from other SEO modules is included.
