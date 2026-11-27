# canonical: Canonical URL

Check 17 of the catalogue. Severity: warning. Priority: high. Group: indexing. Reads the rendered page: yes.
Class: `Restruct\Silverstripe\SEO\Checks\Rules\CanonicalCheck`.

Heading in the panel: "Canonical URL is right" when it passes, "Canonical URL is wrong" when it fails.

## What it checks

The canonical URL(s) in the rendered head: more than one with different values, not a complete http(s) URL, or pointing at another URL than the page's own. No canonical passes.

## Why

A canonical pointing elsewhere asks search engines to list that URL instead of this page. That is right for a real duplicate and wrong for anything else.

## Thresholds

Compared without a trailing slash; scheme and host case-insensitive.

Configured on the class, for example:

```yaml
Restruct\Silverstripe\SEO\Checks\Rules\CanonicalCheck:
  priority: low
```

Disable it with `Restruct\Silverstripe\SEO\Checks\CheckRunner.checks.canonical.enabled: false`.

Source: https://developers.google.com/search/docs/crawling-indexing/consolidate-duplicate-urls
Last reviewed: 2026-11-25
