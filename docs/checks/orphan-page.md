# orphan-page: Other pages link to this page

Check 16 of the catalogue. Severity: warning. Priority: medium. Group: links. Reads the rendered page: no.
Class: `Restruct\Silverstripe\SEO\Checks\Rules\OrphanPageCheck`.

## What it checks

The page is not reachable through the menus (it, and every page above it, has "Show in menus" on) and no other record links to it (core's link tracking, so Elemental blocks count). The homepage always passes; a link from the page to itself does not count.

## Why

Search engines find pages by following links. A page reached only through the sitemap is found late and treated as unimportant.

## Thresholds

None. The site-wide link graph is cached (`Psr\SimpleCache\CacheInterface.SeoLinkGraph`, one hour at most) and cleared when any page is written, published, unpublished or archived. Links printed by templates are not seen: ignore the check for pages that are linked that way.

Configured on the class, for example:

```yaml
Restruct\Silverstripe\SEO\Checks\Rules\OrphanPageCheck:
  priority: low
```

Disable it with `Restruct\Silverstripe\SEO\Checks\CheckRunner.checks.orphan-page.enabled: false`.

Source: https://developers.google.com/search/docs/crawling-indexing/links-crawlable
Last reviewed: 2026-11-25
