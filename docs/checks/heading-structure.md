# heading-structure: Heading structure

Check 10 of the catalogue. Severity: tip. Priority: low. Group: content. Reads the rendered page: yes.
Class: `Restruct\Silverstripe\SEO\Checks\Rules\HeadingStructureCheck`.

Heading in the panel: "Headings are in a logical order" when it passes, "Headings are not in a logical order" when it fails.

## What it checks

More than one `<h1>` in the body, or a heading level skipped in the content area (an H2 followed by an H4). The first heading of the content may follow the page's H1.

## Why

Google says the number and order of headings does not matter for ranking. The check is kept for accessibility: screen reader users navigate by the outline (WCAG 1.3.1).

## Thresholds

None.

Configured on the class, for example:

```yaml
Restruct\Silverstripe\SEO\Checks\Rules\HeadingStructureCheck:
  priority: low
```

Disable it with `Restruct\Silverstripe\SEO\Checks\CheckRunner.checks.heading-structure.enabled: false`.

Source: https://www.w3.org/WAI/tutorials/page-structure/headings/
Last reviewed: 2026-11-25
