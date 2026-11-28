# Changelog

All notable changes to this module are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the module uses
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.8.0] - 2026-11-28

Run `sake db:build` after updating: there is a new column (`SiteTree.CanonicalPageID`).

### Added

- **Canonical page**: a page of this site as the canonical, picked in the page tree on the SEO tab (has_one
  `CanonicalPage`). It takes precedence over the free-text Canonical URL, which stays for an original on another
  site (both field descriptions say so). The canonical is the picked page's absolute published link at render
  time; a pick that is not published or no longer exists is passed over (the page keeps the canonical it has
  without it) and the canonical check reports it. The root slash, `updateSeoCanonicalURL`, virtual pages and
  `seo_hidden_fields` / `seo_readonly_fields` (as `CanonicalPage`) apply as before.
- Migration from simpleseo: a canonical link to a page without anchor or query string becomes the canonical
  page; one with an anchor or query string stays a URL. The other sources have no page-based canonical.

### Changed

- The structured data section on the SEO tab is written for editors: a plain summary of what search engines
  get from the page ("Website: Google can show the site name above the search result."), the missing required
  information, and each missing recommended property that a CMS field fills as an action naming the field and
  where it is ("Organisation: fill in Telephone under Settings > SEO."). Gaps no field can fill are no longer
  shown to editors. The JSON-LD and the full list of findings are folded under "For developers", rendered for
  ADMIN members only. en, nl.

- The alt text checks name an image by its file instead of the URL of the variant the page shows
  (`photo__FitWz..._ExtRewriteWy....webp`): the URL is parsed back to the original with core's file ID helpers
  (an `ExtRewrite` variant under its original extension), and the File record is shown by its title, linked to
  its edit form in the Files admin. A URL that is no file of this site (another host, an unknown path) is shown
  as rendered. Stored details carry the URL (`CheckMessage::file()`), so a renamed file shows its new title.

- Ignored checks are listed in the folded list of passed checks, after the passed ones and marked "Ignored",
  instead of in an "Ignored for this page" block of their own. The fold's summary counts both ("18 checks OK,
  2 ignored"), and "Check again" is an icon button with a tooltip, styled like the ignore button.

## [0.7.1] - 2026-11-27

### Changed

- The "About this check" dialog is rendered by the module's own small Markdown subset again (as in 0.6.x), and
  `league/commonmark` is no longer required. It only renders the module's own check docs (headings, code blocks,
  inline code, bold, links) and escapes all text; its tests render every file in `docs/checks`.
- The "About this check" dialog is written for editors: it opens with what the check looks at and why it
  matters (a new `about()` text per check, en and nl) and the check's how-to-fix advice, under the check's own
  title. The developer spec from `docs/checks` (English) follows, folded under "For developers", for ADMIN
  members only; other CMS users do not get it at all.

### Fixed

- The dialog's close button was invisible: it is now the admin's own close control (cancel icon), with a
  translated label.

## [0.7.0] - 2026-11-27

### Changed

- The "About this check" dialog renders the check's spec with `league/commonmark` (^2.7, a new dependency)
  instead of the module's own Markdown parser, configured to escape raw HTML (`html_input: escape`) and to drop
  unsafe links (`allow_unsafe_links: false`); links still open in a new tab with `rel="noopener noreferrer"`.
  The dialog's title is set as the escaped text the endpoint sends, so a title with `&` or quotes is no longer
  shown escaped twice.

### Fixed

- "About this check" only ever opened the GitHub page on a Composer install: `.gitattributes` left all of
  `docs/` out of the dist archive, so the check specs the dialog renders were missing. `docs/checks/` ships now;
  the rest of `docs/` stays out.

## [0.6.1] - 2026-11-27

### Fixed

- The canonical check reported "Canonical URL is wrong" for the homepage of an https site: the in-process
  render came out as http, so the module's own canonical read `http://host` while the check expected the https
  URL. Fixed in restruct/silverstripe-rendered-content-extractor (the render request now takes the scheme and
  host of the site); requires restruct/silverstripe-rendered-content-extractor ^0.1.2.
- The canonical of the site root is written with its slash (`https://host/`): `Director::absoluteURL()` left it
  as `https://host` with `add_trailing_slash` off, for the homepage and for an override of `/`.
- README: a project that sets `$tags['canonical']` in its own `MetaComponents()`/`updateMetaComponents`
  replaces the module's canonical, and the check then judges that value; the check is strict on the scheme.

## [0.6.0] - 2026-11-27

### Changed

- "About this check" opens the check's spec in a dialog inside the CMS instead of a new tab: the module's own
  `docs/checks/<id>.md`, rendered on the server by a new `admin/seo-checks/doc?check=<id>` endpoint (CMS access
  needed, registered module checks only, no raw HTML passed through). It is an info icon with a tooltip next to
  the check's title now; without JavaScript, and for a project's own check, it stays a link to the published
  spec.
- "Ignore for this page" is an icon button with a tooltip at the top right of a finding, instead of a line of
  its own.
- A failed check no longer carries a "Not met" marker: its fail title and severity badge say it. Passed checks
  keep "OK" and skipped ones "Not checked".
- The priority ("High priority" and so on) is an outlined badge.
- The module adds the admin's own Bootstrap modal (`silverstripe/admin:thirdparty/bootstrap/js/dist/modal.js`)
  to the CMS requirements; the admin loads only Bootstrap's tooltip and collapse itself.

## [0.5.1] - 2026-11-27

### Added

- `seo_image_fallback`: relations or methods of a page type tried in order for a sharing image when the page has
  none of its own (a has_one image, the first image of a has_many or many_many, a method returning an image or
  a list), before the `updateSeoImageFallback` hook, which receives that image and can still replace it, and the
  site default. Empty by default; names the page does not have and values that are not public images are passed
  over. The og-image check's advice mentions it.

### Fixed

- **Re-check did nothing in the browser**, and neither did "Ignore for this page", the re-check after a save or
  the re-check of an out-of-date result on open. The panel's script selected the panel with
  `.seo-checks[data-seo-checks]`, a valueless attribute test the admin's entwine does not match, so the rule's
  methods never existed on the panel; no request was sent and no error shown. The script's rules now select
  by class only (the preview's input rule too, now listened for on the form), and a test fails on any
  attribute test in an entwine selector.
- When a check cannot start or its request fails, the panel says so next to the Re-check button (en and nl,
  with the server's reason when there is one) and logs the details to the console, instead of failing silently.
- Results from before an update are checked again: the installed module version is part of the stored check
  fingerprint, so a result stored by another version is out of date. A result whose render failed is out of
  date too (a new `RenderFailed` column, filled on the next check), so a fixed cause, such as the homepage's
  301 before 0.5.0, no longer stays on the panel until someone saves the page. Run `db:build` for the column.

## [0.5.0] - 2026-11-27

### Changed

- The SEO fields have a tab of their own, **SEO**, right after Main, instead of the collapsible "Search engines
  and sharing" toggle below the content: the checks panel at the top, then the search result preview, then the
  fields (meta title, meta description, search engine indexing, canonical URL, sharing, custom meta tags, the
  structured-data preview). Core's MetaDescription and ExtraMeta move along, and core's Metadata toggle is
  removed when that leaves it empty (kept when another module put a field in it). `seo_tab` names another tab;
  `seo_remove_empty_metadata_toggle: false` keeps the empty toggle for code that looks it up later. Code that
  looked up `Root.Main.Metadata` for the module's fields, or the fallback group `Root.SEO.SeoFallbackGroup`,
  finds them on the tab now.

### Fixed

- The robots checkboxes are one group, titled "Search engine indexing" in the label column, with the checkboxes
  and their descriptions aligned in the field column, instead of a heading above checkboxes that each left an
  empty label column.

- The homepage was never checked: the panel said the page responded with status 301 and skipped every check that
  reads the rendered page. The cause was in restruct/silverstripe-rendered-content-extractor (it rendered the
  homepage as a redirect to the root); fixed there, so update that library along with this module.
- A failed check read as if it passed: its heading was the check's goal ("Title is unique") while the finding
  was that it is not. Every check now has a pass and a fail title (`AbstractCheck::passTitle()` and
  `failTitle()`, en and nl), shown by outcome, with a **Not met** / **OK** / **Not checked** marker next to the
  severity badge; the passed checks are listed (folded away) under their pass titles. Stored results keep their
  message keys and are translated when shown, so rows checked before this release get the new headings too; a
  row of a check that is no longer registered shows its stored goal title.
- A render that redirects to the page's own address gets its own message and advice in the panel, instead of the
  general "it redirected (status 301)", which read as if the page were sent elsewhere.

## [0.4.1] - 2026-11-26

### Fixed

- The site-check and migration tasks print in the site's locale: their titles, descriptions and option help, the
  site-check summary with the pages left and the `--from-id` to continue with, the migration report lines,
  totals, notes and warnings, and the mappers' hints. The queued site check's list of failed pages too. Dutch
  translations for all of them.

### Documentation

- README, migrating: remove a project class that extends or is injected for the old module's classes together
  with the old package, or `db:build` fails on the missing parent class.
- README, records shown on a page: what the structured-data WebPage and breadcrumb describe on a holder's
  sub-action with and without `setSeoSubject()`, how a project's own JSON-LD for the record coexists with the
  graph or moves into it (`schema_map`, `provideSchemaData()`), and that a canonical the project sets itself (a
  `MetaComponents()` override, a `Link` header) is left alone, with `updateSeoCanonicalURL` to change it
  everywhere.

## [0.4.0] - 2026-11-26

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
- `MetaDescriptionFallback()` on a page class: the fallback description when none is typed, before the module
  derives one from the content (an empty return leaves it to the module; the hook runs after either).
- `SeoTitleFromCode()` on pages, and the search result preview shows the title from PHP when the page overrides
  `getMetaTitle()` or hides or locks the MetaTitle field.
- A nested `schema_map` over a relation takes `limit` and `sort` (`hasCourseInstance: { from: Runs, sort:
  'StartDate ASC', limit: 50, ... }`); the limit counts the nodes that are output.

### Changed

- Requires restruct/silverstripe-rendered-content-extractor for rendering a page in-process and narrowing it to
  its content.
- Content checks read the content area the render library's `ContentNarrower.custom_content_selectors` and
  `ContentNarrower.content_selectors` find (by
  default the visible `<main>`, else `[role=main]`, else `<body>`), so a theme that marks its content with
  `role="main"` instead of `<main>`, or a page type with its own container, is checked on that container rather
  than the whole body.

### Removed

- The `Restruct\Silverstripe\SEO\Rendering\PageRenderer` interface (never implemented): the shared
  `Restruct\Silverstripe\RenderedContentExtractor\PageRenderer` service takes its place, without a fallback
  copy.

### Fixed

- A page class's getter override of a robots flag (`getMetaRobotsNoIndex()`, ...) was ignored: the flags were read
  with `getField()`. It now decides the robots meta tag, `X-Robots-Tag`, the sitemap, the noindex check and which
  checks run.
- The duplicate title and description checks compared the MetaTitle and MetaRobotsNoIndex columns in SQL, so a
  page class that generates its title or noindex in a getter was compared on values it does not output. Candidates
  are now confirmed in PHP, and all pages of a class with a `getMetaTitle()` override are compared on their real
  title. Those titles, and the noindex verdicts of other pages, are computed once per `SiteData`; one `SiteData`
  is shared by the pages of a task run or a job step (`CheckRunner::run()`, `collect()`, `checkPage()` and
  `SiteCheck::checkPage()` take an optional `SiteData`).

- An action that removed the module's `X-Robots-Tag` or `Link` header from the controller's response got it put
  back after the action. Headers the project sets (in `init()` or an action) were already never overwritten; both
  are now covered by tests.

- A shown record (SEO subject) read by duck-typing ignored getter overrides: fields were read before getters and
  robots with `getField()`. A `get<Name>()` getter now wins over the column, and a robots getter without a column
  counts as the record's own setting.

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
