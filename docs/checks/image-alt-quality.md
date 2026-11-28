# image-alt-quality: Alt text describes the image

Check 12 of the catalogue. Severity: tip. Priority: medium. Group: images. Reads the rendered page: yes.
Class: `Restruct\Silverstripe\SEO\Checks\Rules\ImageAltQualityCheck`.

Heading in the panel: "Alt text describes the image" when it passes, "Alt text does not describe the image" when it fails.

## What it checks

Alt texts that look like a file or camera name ("IMG_1234", "DSC-0042", "photo2"; a sentence such as "Photo 2 of the new building" is not one), start with "image of" / "afbeelding van" and the like, are very short or very long, are the same on two different images, or repeat a word.

Each image is listed by its file: a variant URL (resized, or converted to another format such as `photo__FitWz..._ExtRewriteWy....webp`) is parsed back to the original with core's file ID helpers, and the File record is shown by its title, linked to its edit form in the Files admin. An image that is no file of this site (another host, an unknown path) is listed by its URL as rendered.

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
