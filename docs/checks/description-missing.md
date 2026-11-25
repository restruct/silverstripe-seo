# description-missing: Meta description

Check 6 of the catalogue. Severity: warning. Priority: medium. Group: description. Reads the rendered page: no (uses it when there is one).
Class: `Restruct\Silverstripe\SEO\Checks\Rules\DescriptionMissingCheck`.

## What it checks

No meta description was typed, and the one in the rendered head (derived from the page content) is missing or shorter than `weak_fallback_length`. When the text sits in blocks, which the field-based fallback cannot read, the first paragraph of the rendered content is offered as a suggestion.

## Why

Google uses the meta description for the snippet when it describes the page better than the page text does. Soft on purpose: Google often writes its own snippet anyway.

## Thresholds

`weak_fallback_length`: 50 characters. `suggestion_length`: 155.

Configured on the class, for example:

```yaml
Restruct\Silverstripe\SEO\Checks\Rules\DescriptionMissingCheck:
  priority: low
```

Disable it with `Restruct\Silverstripe\SEO\Checks\CheckRunner.checks.description-missing.enabled: false`.

Source: https://developers.google.com/search/docs/appearance/snippet
Last reviewed: 2026-11-25
