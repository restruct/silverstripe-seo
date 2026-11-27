# title-missing: Page title in search results

Check 2 of the catalogue. Severity: problem. Priority: high. Group: title. Reads the rendered page: no (uses it when there is one).
Class: `Restruct\Silverstripe\SEO\Checks\Rules\TitleMissingCheck`.

Heading in the panel: "Page has a title for search results" when it passes, "Page has no title for search results" when it fails.

## What it checks

The rendered page has no `<title>` element, or an empty one.

## Why

The title is the link text of the search result. Without one, search engines make one up from the page. With this module the title falls back to the page title plus the site name, so the check mostly catches a template that does not print `$MetaTags` (or a title of its own).

## Thresholds

None.

Configured on the class, for example:

```yaml
Restruct\Silverstripe\SEO\Checks\Rules\TitleMissingCheck:
  priority: low
```

Disable it with `Restruct\Silverstripe\SEO\Checks\CheckRunner.checks.title-missing.enabled: false`.

Source: https://developers.google.com/search/docs/appearance/title-link
Last reviewed: 2026-11-25
