# image-alt-missing: Images have alt text

Check 11 of the catalogue. Severity: warning. Priority: high. Group: images. Reads the rendered page: yes.
Class: `Restruct\Silverstripe\SEO\Checks\Rules\ImageAltMissingCheck`.

## What it checks

Images in the content area (blocks and template images included) without an `alt` attribute. An empty alt (`alt=""`) passes: HTML defines it as the mark of a decorative image, with or without `role="presentation"` or `aria-hidden="true"`.

## Why

Google uses alt text to understand images, and it is what screen readers read out. Decided on review (2026-11-25): a bare empty alt is accepted as decorative, as the HTML spec has it, although editors sometimes leave the field empty by accident; whether an image really is decorative cannot be told from the markup.

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
