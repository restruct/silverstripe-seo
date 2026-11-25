# image-alt-missing: Images have alt text

Check 11 of the catalogue. Severity: warning. Priority: high. Group: images. Reads the rendered page: yes.
Class: `Restruct\Silverstripe\SEO\Checks\Rules\ImageAltMissingCheck`.

## What it checks

Images in the content area (blocks and template images included) without an `alt` attribute, or with an empty one. An image marked decorative (`alt=""` with `role="presentation"`/`"none"` or `aria-hidden="true"`) passes.

## Why

Google uses alt text to understand images, and it is what screen readers read out. An empty alt without a marker is reported because editors leave the field empty by accident far more often than on purpose.

## Thresholds

`max_listed`: 10.

Configured on the class, for example:

```yaml
Restruct\Silverstripe\SEO\Checks\Rules\ImageAltMissingCheck:
  priority: low
```

Disable it with `Restruct\Silverstripe\SEO\Checks\CheckRunner.checks.image-alt-missing.enabled: false`.

Source: https://developers.google.com/search/docs/appearance/google-images
Last reviewed: 2026-11-25
