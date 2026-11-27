# unpublished-links: Link targets are published

Check 13b of the catalogue. Severity: warning. Priority: medium. Group: links. Reads the rendered page: yes.
Class: `Restruct\Silverstripe\SEO\Checks\Rules\UnpublishedLinksCheck`.

Heading in the panel: "Link targets are published" when it passes, "Links point to unpublished pages or files" when it fails.

## What it checks

Links and images in the content area that point at a page or file that exists only as a draft (not in the live stage). Resolved as in `broken-links`, without HTTP requests.

## Why

The link works in the draft and breaks for visitors if the target is not published with or before this page. Split off from `broken-links` (a problem) on review (2026-11-25): an unpublished target is often going live together with the page, so it is a warning.

## Thresholds

`max_listed`: 15 (inherited from `BrokenLinksCheck`).

Configured on the class, for example:

```yaml
Restruct\Silverstripe\SEO\Checks\Rules\UnpublishedLinksCheck:
  priority: low
```

Disable it with `Restruct\Silverstripe\SEO\Checks\CheckRunner.checks.unpublished-links.enabled: false`.

Source: https://developers.google.com/search/docs/crawling-indexing/links-crawlable
Last reviewed: 2026-11-25
