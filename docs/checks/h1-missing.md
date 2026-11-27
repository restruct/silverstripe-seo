# h1-missing: Main heading (H1)

Check 9 of the catalogue. Severity: warning. Priority: medium. Group: content. Reads the rendered page: yes.
Class: `Restruct\Silverstripe\SEO\Checks\Rules\H1MissingCheck`.

Heading in the panel: "Page has a main heading (H1)" when it passes, "Main heading (H1) is missing" when it fails.

## What it checks

There is no `<h1>` anywhere in the rendered body (the page header above `<main>` included).

## Why

Headings tell visitors, screen reader users and search engines what the page is about. Accessibility as much as SEO.

## Thresholds

None.

Configured on the class, for example:

```yaml
Restruct\Silverstripe\SEO\Checks\Rules\H1MissingCheck:
  priority: low
```

Disable it with `Restruct\Silverstripe\SEO\Checks\CheckRunner.checks.h1-missing.enabled: false`.

Source: https://developers.google.com/search/docs/fundamentals/seo-starter-guide
Last reviewed: 2026-11-25
