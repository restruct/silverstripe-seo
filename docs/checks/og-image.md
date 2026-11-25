# og-image: Sharing image

Check 21 of the catalogue. Severity: tip. Priority: low. Group: social. Reads the rendered page: no (uses it when there is one).
Class: `Restruct\Silverstripe\SEO\Checks\Rules\OgImageCheck`.

## What it checks

No `og:image` in the rendered head: no sharing image on the page, from a project fallback, or as the site default.

## Why

Shared links show without a picture, or with whatever image the other site picks.

## Thresholds

None.

Configured on the class, for example:

```yaml
Restruct\Silverstripe\SEO\Checks\Rules\OgImageCheck:
  priority: low
```

Disable it with `Restruct\Silverstripe\SEO\Checks\CheckRunner.checks.og-image.enabled: false`.

Source: https://ogp.me/
Last reviewed: 2026-11-25
