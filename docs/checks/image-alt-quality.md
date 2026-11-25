# image-alt-quality: Alt text describes the image

Check 12 of the catalogue. Severity: tip. Priority: medium. Group: images. Reads the rendered page: yes.
Class: `Restruct\Silverstripe\SEO\Checks\Rules\ImageAltQualityCheck`.

## What it checks

Alt texts that look like a file or camera name, start with "image of" / "afbeelding van" and the like, are very short or very long, are the same on two different images, or repeat a word.

## Why

Google asks for descriptive, useful alt text and warns against keyword stuffing. The idea follows Yoast's alt-text assessment (yoastseo 3.7); no code or word list is taken from it.

## Thresholds

`min_length`: 5, `max_length`: 150 characters. `stuffing_repeats`: a word of 4 or more letters used 3 times. `redundant_prefixes`: a list (en + nl). `max_listed`: 10.

Configured on the class, for example:

```yaml
Restruct\Silverstripe\SEO\Checks\Rules\ImageAltQualityCheck:
  priority: low
```

Disable it with `Restruct\Silverstripe\SEO\Checks\CheckRunner.checks.image-alt-quality.enabled: false`.

Source: https://developers.google.com/search/docs/appearance/google-images
Last reviewed: 2026-11-25
