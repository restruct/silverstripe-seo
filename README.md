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
- **Sitemap bridge**: with [wilr/silverstripe-googlesitemaps](https://github.com/wilr/silverstripe-googlesitemaps)
  installed, noindex pages are left out of `sitemap.xml`.

Planned for later versions: structured data (JSON-LD) mapped from existing records, page checks on the rendered
page (Elemental blocks included), a site-wide report, and optional AI suggestions.

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

# og:type per page class
App\Model\ArticlePage:
  seo_og_type: article
```

Site-wide, in the CMS under Settings > SEO:

- **Title suffix**: replaces the site title after every page title (e.g. a shorter brand name).
- **Default sharing image**: used when a page has no sharing image of its own.

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

## Extension hooks

Called on the page (implement them in an `Extension` applied to `SiteTree` or a page class):

| Hook | Use it to |
|---|---|
| `updateSeoTitle(string &$title)` | change the complete `<title>` text |
| `updateSeoDescriptionFallback(?string &$description)` | supply a better fallback description (also called on a shown record) |
| `updateSeoRobotsDirectives(array &$directives)` | add directives such as `max-image-preview:large` |
| `updateSeoCanonicalURL(?string &$url, ?HTTPRequest $request)` | change the canonical |
| `updateSeoImageFallback(?Image &$image)` | supply a sharing image when the page has none (a hero image, say) |
| `updateSeoMetaComponents(array &$tags)` | change any head tag this module produced, just before output |

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
