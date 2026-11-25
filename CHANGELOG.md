# Changelog

All notable changes to this module are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the module uses
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.2.0] - 2026-11-25

### Added

- Structured data (JSON-LD): one `<script type="application/ld+json">` per page with a single `@graph`, nodes
  deduplicated by `@id`, JSON escaped so no value can close the script element. WebSite, Organization (mapped
  from SiteConfig), WebPage (type per page class via `schema_webpage_type`), BreadcrumbList, the page's own node,
  the shown record's node as the WebPage's `mainEntity`, records from `schema_includes`, and an ItemList from
  `schema_item_list`.
- `SchemaSource` extension: map any page type or DataObject in YAML (`schema_type`, `schema_map`) with fields,
  methods, relation paths, literals, date + time pairs, nested maps, has_many and many_many lists and
  references to site nodes. Or define `provideSchemaData(SchemaContext $ctx)` on the record; the
  `updateSchemaData` and `updateSeoSchemaGraph` hooks tweak nodes and the graph.
- Stable `@id`s: `<base>/#website`, `<base>/#organization`, `<page URL>#webpage`, `#breadcrumb`, `#itemlist`,
  and `<record link>#<type>-<ID>` for records.
- `SchemaTypeRegistry` with required and recommended properties per type after Google's documentation; nodes
  missing a required property are left out, and `getSchemaWarnings()` lists what is missing.
- `SchemaDates`: Date and Time fields combined into ISO 8601 with the offset of `seo_schema_timezone`.
- SiteConfig: a logo and a list of profiles on other sites, for the Organization node.
- A read-only preview of the page's structured data, with warnings, in the page editor.
- `seo_output_structured_data` (default true) to switch the output off.
- `{ html: Field }` map values (simple markup only), either-or requirements in the type registry (a remote
  JobPosting, an online Event), `schema_includes_limit` (default 50) with eager loading of what an included
  record's map reads, `schema_allowed_methods`, `schema_check_can_view`, the `updateSeoSchemaLocale` hook, and
  `type-not-registered` and `method-not-allowed` warnings.

### Fixed (phase 3 review, before release)

- Map values are read through `obj()`: overriding getters and `casting` apply, so a method cast as HTMLText is
  plain text.
- A map can no longer call arbitrary methods (`delete`); only fields, relations, getters, cast methods and
  `schema_allowed_methods`.
- The WebPage `url` and `@id` follow the canonical (action paths, `seo_canonical_query_params`).
- Datetime values are read as server time and expressed in `seo_schema_timezone`.
- A throwing source or a value JSON cannot encode no longer breaks the page or empties the graph.
- Records without a link of their own carry their class in the `@id`, so two classes no longer merge.
- Paths through two lists give one flat list; plain text keeps a line per block.
- Records an anonymous visitor may not see are left out; the CMS preview uses the site locale; `mainEntity` is
  chosen after incomplete nodes are left out.

## [0.1.0] - 2026-11-25

### Added

- Meta title (`MetaTitle`, shared with kinglozzer/metatitle and the older liveseo module) with a configurable
  separator and site name suffix, guarded against repeating the site name.
- Meta description fallback from the first paragraph of the content, cut at a word boundary, with the
  `updateSeoDescriptionFallback` hook.
- Robots per page (noindex, nofollow, nosnippet, noarchive) as a meta tag and an `X-Robots-Tag` header.
- Canonical URL as a link tag and a `Link` header: self-referencing by default, optional override, action URLs
  get their own canonical, virtual pages point at their source.
- Open Graph and `twitter:card` tags with fallbacks for description and image (page image, the
  `updateSeoImageFallback` hook, a site-wide default image), the image cropped to 1200 x 630.
- Records shown on a page (`setSeoSubject()`, `SeoSubject` interface or duck-typing) supply their own title,
  description, canonical (tracking parameters removed), sharing values and robots.
- Bridge to wilr/silverstripe-googlesitemaps: noindex pages are left out of the sitemap.
- SiteConfig settings: default sharing image, title suffix.
- English and Dutch translations.
- `PageRenderer` interface as the seam for checks on the rendered page in a later version.
- `seo_output_canonical` and `seo_output_open_graph` (both default true), for themes that print these tags
  themselves.
- `seo_noindex_non_live` (default false): noindex, nofollow on every page outside the live environment.
- `seo_meta_title_field_providers`: no second MetaTitle field when kinglozzer/metatitle (listed by default) or
  another listed extension already adds one.

### Fixed

Found in review, before the first release:

- An action on the homepage gets `/home/<action>` as its canonical, not `/<action>`.
- An action's canonical keeps every segment its url_handler matched (`/archive/2024/05/12`), not only
  Action/ID/OtherID, and has its action segment lowercased.
- Error pages (including the static `error-NNN.html`) and responses with status 400 or higher get no canonical,
  no `og:url` and no `Link` header.
- Redirects, including redirector pages, get no `X-Robots-Tag` or `Link` header.
- The SEO fields are read-only on a virtual page, which copies them from its source on every save.
- The description fallback separates words at line breaks and block elements, decodes entities once instead of
  twice, and strips only registered shortcodes, so bracketed text like `[sic]` stays.
- The site name is no longer taken for the title suffix when a title merely ends in its letters ("Workshop" on a
  site called "Shop").
- `og:image` only uses a public image (published, in the public store, viewable by anyone).
- A shown record without an image falls back to the `updateSeoImageFallback` hook and the site default image,
  not to the image of the page that lists it.
