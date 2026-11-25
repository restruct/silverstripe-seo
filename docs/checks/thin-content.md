# thin-content: Amount of text

Check 19 of the catalogue. Severity: tip. Priority: low. Group: content. Reads the rendered page: yes.
Class: `Restruct\Silverstripe\SEO\Checks\Rules\ThinContentCheck`.

## What it checks

The number of words in the content area (blocks included), when it is very low.

## Why

Information only. Google: length alone does not matter, so there is no word-count target; but a page with a handful of words gives searchers little to go on. A contact page or a form can be fine as it is.

## Thresholds

`min_words`: 50. Not a target to write towards.

Configured on the class, for example:

```yaml
Restruct\Silverstripe\SEO\Checks\Rules\ThinContentCheck:
  priority: low
```

Disable it with `Restruct\Silverstripe\SEO\Checks\CheckRunner.checks.thin-content.enabled: false`.

Source: https://developers.google.com/search/docs/fundamentals/creating-helpful-content
Last reviewed: 2026-11-25
