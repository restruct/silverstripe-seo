# structured-data: Structured data is complete

Check 20 of the catalogue. Severity: problem. Priority: medium. Group: structured-data. Reads the rendered page: no (uses it when there is one).
Class: `Restruct\Silverstripe\SEO\Checks\Rules\StructuredDataCheck`.

## What it checks

A JSON-LD block in the rendered page that is not valid JSON (from a template or another module), and nodes of this module's graph that lack a required property and are therefore left out (`getSchemaWarnings()`, severity `required`). Missing recommended properties are shown in the structured data preview, not here.

## Why

Structured data with errors is not used for rich results.

## Thresholds

The required properties per type are those of `SchemaTypeRegistry`.

Configured on the class, for example:

```yaml
Restruct\Silverstripe\SEO\Checks\Rules\StructuredDataCheck:
  priority: low
```

Disable it with `Restruct\Silverstripe\SEO\Checks\CheckRunner.checks.structured-data.enabled: false`.

Source: https://developers.google.com/search/docs/appearance/structured-data/sd-policies
Last reviewed: 2026-11-25
