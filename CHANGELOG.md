# Changelog

All notable changes to this module are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the module uses
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- SEO checks on the rendered draft (Elemental blocks and template text included), rendered in-process as the
  CMS user by restruct/silverstripe-rendered-content-extractor: checks 1-21 and 23 of the catalogue (the focus
  keyword check was dropped), with links to draft-only targets as a warning of their own (`unpublished-links`) next to
  `broken-links`; an empty alt counts as decorative, each a class behind the `Check` interface, registered in `CheckRunner.checks` and configurable
  per class (thresholds, severity, priority). A noindex page gets only the noindex check.
- A spec per check in `docs/checks/<id>.md` (what, why, thresholds, source, last reviewed) and
  `docs/dropped-checks.md`.
- `SeoCheckResult` (stored results per page and stage, counts per severity, a stale flag set on save) and
  `SeoCheckIgnore` (an editor ignores a check for a page; who and when are kept).
- The SEO checks panel in the page editor: counts, failing checks grouped with priority, details and how to fix,
  ignored and skipped checks; no score. Server-rendered, so it reads without JavaScript.
- A search result preview below the meta description, updated per keystroke, with the title width and the
  description length checked against the same thresholds as the stored checks.
- `admin/seo-checks/recheck` and `admin/seo-checks/ignore` (POST, CSRF token, CMS access, `canEdit()` on the
  page): the panel re-checks after a save and on its button; nothing is rendered during the save itself.
- `LinkResolver` hook `updateResolveInternalLink()` for translation modules (Fluent is not supported yet).
- `client/dist` is exposed (`extra.expose`), so the panel's script and styles are served.
- `SeoDescriptionFallback()` on pages: the description derived when none is typed.
- English and Dutch strings for the checks, the panel and the preview (lang/en.yml, lang/nl.yml).
- The site report (**Reports > SEO status**, `SeoSiteReport`): per page the counts per severity, last checked,
  status (up to date, out of date, not checked), indexable and failing checks; filters on severity, failing
  check, page type and status; sorting; a summary ("pages without problems: N of M indexable pages", unchecked
  and out-of-date counts, the checks failing on most pages the reader may edit); no score. Needs the new `VIEW_SEO_REPORT`
  permission plus Reports access; lists only pages the reader may view, details only for pages they may edit.
- CSV export of the report with every formula-like cell (`= + - @`, tab, carriage return) prefixed with `'`.
- `SeoSiteCheckJob` (with symbiote/silverstripe-queuedjobs, now a suggest): checks every eligible page in
  batches, resumable, skipping fresh results; and `sake tasks:seo-check-site` (CLI only; `--only-stale`, `--limit=N`,
  `--from-id=N`) for sites without queuedjobs. Both render as a visitor with the draft readable, or as `SiteCheck.run_as`, never as
  the member who started them.
- Stored results also go stale when another page changes (for a result holding a check that reads other
  pages: `isCrossPage()` / `cross_page`, set on the duplicate, link and orphan checks) and when the check
  configuration changes (`SeoCheckResult.ConfigHash`, `CheckRunner::configHash()`, `CheckRunner::CHECKS_VERSION`).
  `StaleContext` decides many results with a fixed number of queries.
- Organisation contact details on Settings > SEO (`SeoOrgDescription`, `SeoOrgEmail`, `SeoOrgTelephone`,
  `SeoOrgStreet`, `SeoOrgPostalCode`, `SeoOrgLocality`, `SeoOrgRegion`, `SeoOrgCountry`), mapped by the default
  Organization `schema_map` to `description`, `email`, `telephone` and a nested PostalAddress `address`, output
  only with a street or a place.
- A nested `schema_map` can declare `'@required'` (the registry's notation, `a | b` for either): without those
  properties the nested node is left out. `SchemaTypeRegistry::missingOf()` checks such a list.
- The liveseo migration copies the single site organisation's description, email, telephone and its one
  PostalAddress to those SiteConfig fields (dry run first, filled values kept, too-long values invalid, two or
  more addresses not guessed), reports other LocalBusiness rows (branches), and notes that code reading the old
  organisation table (a partner feed) should read SiteConfig after migrating.
- `seo_hidden_fields` and `seo_readonly_fields` per page class: the module's fields (and MetaDescription) by
  name, left out of the edit form or shown read-only; output is not affected.

### Changed

- Requires restruct/silverstripe-rendered-content-extractor for rendering a page in-process and narrowing it to
  its content.
- Content checks read the content area the render library's `ContentNarrower.content_selectors` finds (by
  default the visible `<main>`, else `[role=main]`, else `<body>`), so a theme that marks its content with
  `role="main"` instead of `<main>`, or a page type with its own container, is checked on that container rather
  than the whole body.

### Removed

- The `Restruct\Silverstripe\SEO\Rendering\PageRenderer` interface (never implemented): the shared
  `Restruct\Silverstripe\RenderedContentExtractor\PageRenderer` service takes its place, without a fallback
  copy.

## [0.3.0] - 2026-11-25

### Added

- `MigrateFromSeoModuleTask` (`sake tasks:seo-migrate --source=<name>`): copies data from another SEO module,
  with one mapper per source behind `SeoMigrationMapper`: `liveseo` (the older liveseo module, including its
  StructuredData organisation logo and profiles), `hubertusanton`, `plasticstudio` (and the forks sharing its
  schema), `simpleseo` (with kinglozzer/metatitle; canonical links resolved per stage) and `jellygnite`.
  A dry run unless `--apply`; filled-in values are kept unless `--overwrite`; a report line per column and
  stage. Draft and live tables are written separately, nothing is published, old columns are never changed,
  and columns in an `_obsolete_` table are found too. A project registers its own mapper in
  `MigrateFromSeoModuleTask.mappers`.
  - A value longer than the target column (from information_schema: characters for VARCHAR, bytes for TEXT)
    is invalid, never cut off: Silverstripe's sql_mode is not strict, so MySQL would truncate it silently.
  - Numbered renames (`_obsolete_Page2`, `_obsolete_Page_Live2`) are found; the highest number wins, with a
    warning when more than one holds the column.
  - With several source columns, an unusable value falls through to the next column.
  - The live stage of an image copy needs a published file; otherwise it is counted as unpublished.
  - `--apply` runs in a transaction that any Throwable rolls back; on a Fluent site (`localised_tables`) it is
    refused, and a dry run warns.
  - The unknown `--source` value is escaped in the output.

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
