# Changelog

All notable changes to this module are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the module uses
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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
