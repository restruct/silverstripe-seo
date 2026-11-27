# description-length: Meta description length

Check 8 of the catalogue. Severity: tip. Priority: low. Group: description. Reads the rendered page: no (uses it when there is one).
Class: `Restruct\Silverstripe\SEO\Checks\Rules\DescriptionLengthCheck`.

Heading in the panel: "Meta description has a good length" when it passes, "Meta description is too short or too long" when it fails.

## What it checks

The length (in characters) of the description search engines get, typed or derived. An empty description is check 6's business.

## Why

Advisory: there is no limit, snippets are cut to the device width, and a very short description is more likely to be replaced.

## Thresholds

`min_length`: 70, `max_length`: 160. The live counter in the CMS uses the same two numbers.

Configured on the class, for example:

```yaml
Restruct\Silverstripe\SEO\Checks\Rules\DescriptionLengthCheck:
  priority: low
```

Disable it with `Restruct\Silverstripe\SEO\Checks\CheckRunner.checks.description-length.enabled: false`.

Source: https://developers.google.com/search/docs/appearance/snippet
Last reviewed: 2026-11-25
