# description-duplicate: Meta description is unique

Check 7 of the catalogue. Severity: warning. Priority: medium. Group: description. Reads the rendered page: no.
Class: `Restruct\Silverstripe\SEO\Checks\Rules\DescriptionDuplicateCheck`.

Heading in the panel: "Meta description is unique" when it passes, "Meta description is not unique" when it fails.

## What it checks

Another indexable page has the same typed meta description. Derived descriptions are not compared.

## Why

Google recommends a different description for each page; identical descriptions are not helpful when several pages show up in results.

## Thresholds

`max_listed`: 5.

Configured on the class, for example:

```yaml
Restruct\Silverstripe\SEO\Checks\Rules\DescriptionDuplicateCheck:
  priority: low
```

Disable it with `Restruct\Silverstripe\SEO\Checks\CheckRunner.checks.description-duplicate.enabled: false`.

Source: https://developers.google.com/search/docs/appearance/snippet
Last reviewed: 2026-11-25
