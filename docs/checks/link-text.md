# link-text: Link text says where it goes

Check 14 of the catalogue. Severity: tip. Priority: medium. Group: links. Reads the rendered page: yes.
Class: `Restruct\Silverstripe\SEO\Checks\Rules\LinkTextCheck`.

## What it checks

Links in the content area whose text (or image alt text, aria-label, title) is empty, or is one of the non-descriptive texts: "click here", "read more", "lees meer", "klik hier", and so on.

## Why

Google uses anchor text to understand the target and recommends descriptive text. Screen reader users often list a page's links out of context (WCAG 2.4.4).

## Thresholds

`non_descriptive_texts`: per language (en, nl); all lists apply to every page. Compared case-insensitively, punctuation ignored. `max_listed`: 10.

Configured on the class, for example:

```yaml
Restruct\Silverstripe\SEO\Checks\Rules\LinkTextCheck:
  priority: low
```

Disable it with `Restruct\Silverstripe\SEO\Checks\CheckRunner.checks.link-text.enabled: false`.

Source: https://developers.google.com/search/docs/crawling-indexing/links-crawlable
Last reviewed: 2026-11-25
