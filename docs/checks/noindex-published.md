# noindex-published: Published page hidden from search engines

Check 1 of the catalogue. Severity: problem. Priority: high. Group: indexing. Reads the rendered page: no.
Class: `Restruct\Silverstripe\SEO\Checks\Rules\NoindexPublishedCheck`.

## What it checks

The page is published and asks search engines not to list it: `noindex` in its robots directives (the "Hide from search results" box, or a project hook that adds it).

## Why

A forgotten noindex keeps a page out of search results completely, and nothing on the page itself shows it. Often it is intentional (a thank-you page, a campaign page); then the editor ignores the check for that page.

This is the only check a noindex page gets: the others are about how a page shows up in search results.

## Thresholds

None. The site-wide `seo_noindex_non_live` setting (every page noindex on a test environment) is not counted as the page asking for it, so test sites are checked normally.

Configured on the class, for example:

```yaml
Restruct\Silverstripe\SEO\Checks\Rules\NoindexPublishedCheck:
  priority: low
```

Disable it with `Restruct\Silverstripe\SEO\Checks\CheckRunner.checks.noindex-published.enabled: false`.

Source: https://developers.google.com/search/docs/crawling-indexing/block-indexing
Last reviewed: 2026-11-25
