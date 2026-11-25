# title-width: Title fits in a search result

Check 5 of the catalogue. Severity: tip. Priority: medium. Group: title. Reads the rendered page: no.
Class: `Restruct\Silverstripe\SEO\Checks\Rules\TitleWidthCheck`.

## What it checks

The estimated width of the full title, in pixels, from a table of Arial character widths at 20 px (`Restruct\Silverstripe\SEO\Text\TitleWidth`).

## Why

Search results cut titles off at the width of the result, not at a number of characters. Advisory only: Google sets no limit and does not penalise a long title, but the end is not shown.

## Thresholds

`max_pixels`: 600. The table, font size and default width are config of `TitleWidth`; the CMS preview uses the same numbers.

Configured on the class, for example:

```yaml
Restruct\Silverstripe\SEO\Checks\Rules\TitleWidthCheck:
  priority: low
```

Disable it with `Restruct\Silverstripe\SEO\Checks\CheckRunner.checks.title-width.enabled: false`.

Source: https://developers.google.com/search/docs/appearance/title-link
Last reviewed: 2026-11-25
