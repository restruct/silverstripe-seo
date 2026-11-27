# title-duplicate: Title is unique

Check 4 of the catalogue. Severity: warning. Priority: high. Group: title. Reads the rendered page: no.
Class: `Restruct\Silverstripe\SEO\Checks\Rules\TitleDuplicateCheck`.

Heading in the panel: "Title is unique" when it passes, "Title is not unique" when it fails.

## What it checks

Another indexable page (not noindex, not a redirector, virtual or error page) has the same title for search, compared in the draft stage as the database compares (case-insensitive with the default collation).

## Why

Results with the same title cannot be told apart, and Google may rewrite them. Two pages with the same title often are the same page twice.

## Thresholds

`max_listed`: how many other pages are named (5).

Configured on the class, for example:

```yaml
Restruct\Silverstripe\SEO\Checks\Rules\TitleDuplicateCheck:
  priority: low
```

Disable it with `Restruct\Silverstripe\SEO\Checks\CheckRunner.checks.title-duplicate.enabled: false`.

Source: https://developers.google.com/search/docs/appearance/title-link
Last reviewed: 2026-11-25
