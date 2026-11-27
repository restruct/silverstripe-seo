# orphan-page: Other pages link to this page

Check 16 of the catalogue. Severity: warning. Priority: medium. Group: links. Reads the rendered page: no.
Class: `Restruct\Silverstripe\SEO\Checks\Rules\OrphanPageCheck`.

Heading in the panel: "Other pages link to this page" when it passes, "No other page links to this page" when it fails.

## What it checks

The page is not reachable through the menus (it, and every page above it, has "Show in menus" on) and no other record links to it (core's link tracking, so Elemental blocks count). The homepage always passes; a link from the page to itself does not count. A child of a parent whose class is in `lists_children_classes` (a blog holder, a news archive: templates that list their children; empty by default) passes when that parent is reachable. An extension can decide other cases in `updateIsOrphan(bool &$orphan, PageAnalysis $analysis)`.

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
