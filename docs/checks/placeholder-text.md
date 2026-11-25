# placeholder-text: No placeholder text

Check 18 of the catalogue. Severity: problem. Priority: high. Group: content. Reads the rendered page: yes.
Class: `Restruct\Silverstripe\SEO\Checks\Rules\PlaceholderTextCheck`.

## What it checks

Placeholder text in the content area, the title or the description: "lorem ipsum", "dolor sit amet", TODO, FIXME, TBD, xxx, "[placeholder]", "tekst volgt".

## Why

Placeholder text on a live page is unhelpful content for visitors and search engines alike, and looks unfinished.

## Thresholds

`patterns`: PCRE patterns keyed by name; set one to an empty string to switch it off. TODO, FIXME and TBD match in capitals only; xxx matches only as a word of its own (not in XXXL).

Configured on the class, for example:

```yaml
Restruct\Silverstripe\SEO\Checks\Rules\PlaceholderTextCheck:
  priority: low
```

Disable it with `Restruct\Silverstripe\SEO\Checks\CheckRunner.checks.placeholder-text.enabled: false`.

Source: https://developers.google.com/search/docs/fundamentals/creating-helpful-content
Last reviewed: 2026-11-25
