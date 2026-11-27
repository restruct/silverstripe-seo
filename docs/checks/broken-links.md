# broken-links: Links and files work

Check 13 of the catalogue. Severity: problem. Priority: high. Group: links. Reads the rendered page: yes.
Class: `Restruct\Silverstripe\SEO\Checks\Rules\BrokenLinksCheck`.

Heading in the panel: "Links and files work" when it passes, "Links or files do not work" when it fails.

## What it checks

Links (`a[href]`) and images or media (`img`, `source[src]`) in the content area that point at a page or file that does not exist. A target that exists only as a draft is not broken: `unpublished-links` reports it, as a warning. Sources: core's link tracking (the `ss-broken` class it puts on a link shortcode to a deleted page or file, and the empty href such a link renders as), and every other internal URL resolved against the database: pages with `SiteTree::get_by_link()` (the longest leading part of the path that is a page counts, the rest is taken as an action), files by name under the assets URL (variants and protected hash folders handled).

## Why

A broken link is a dead end for visitors and for crawlers.

## Thresholds

`max_listed`: 15. No HTTP requests are made: external links and URLs handled by other routes (Security, admin, an API) are not checked.

Configured on the class, for example:

```yaml
Restruct\Silverstripe\SEO\Checks\Rules\BrokenLinksCheck:
  priority: low
```

Disable it with `Restruct\Silverstripe\SEO\Checks\CheckRunner.checks.broken-links.enabled: false`.

Source: https://developers.google.com/search/docs/crawling-indexing/links-crawlable
Last reviewed: 2026-11-25
