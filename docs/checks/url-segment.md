# url-segment: Readable URL

Check 23 of the catalogue. Severity: tip. Priority: low. Group: url. Reads the rendered page: no.
Class: `Restruct\Silverstripe\SEO\Checks\Rules\UrlSegmentCheck`.

## What it checks

The page's URL segment contains underscores, is long, or contains several small words ("the", "of", "de", "van").

## Why

Google recommends simple, readable URLs with words separated by hyphens, not underscores. Changing a published page's URL changes its address (Silverstripe redirects the old one), so this is a tip for new pages more than a reason to rename old ones.

## Thresholds

`max_length`: 60 characters. `stop_words_min`: 2. `stop_words`: per language (en, nl); all lists apply.

Configured on the class, for example:

```yaml
Restruct\Silverstripe\SEO\Checks\Rules\UrlSegmentCheck:
  priority: low
```

Disable it with `Restruct\Silverstripe\SEO\Checks\CheckRunner.checks.url-segment.enabled: false`.

Source: https://developers.google.com/search/docs/crawling-indexing/url-structure
Last reviewed: 2026-11-25
