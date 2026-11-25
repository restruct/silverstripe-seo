# Changelog

All notable changes to this module are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the module uses
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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
