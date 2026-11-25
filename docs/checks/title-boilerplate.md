# title-boilerplate: Title says what the page is about

Check 3 of the catalogue. Severity: warning. Priority: high. Group: title. Reads the rendered page: no.
Class: `Restruct\Silverstripe\SEO\Checks\Rules\TitleBoilerplateCheck`.

## What it checks

The page's own part of the title (meta title, else page title) is the site name; the site name appears in the full title more than once; or the title is a placeholder such as "New Page".

## Why

Google lists boilerplate titles, repeated site names and placeholder titles among the reasons it rewrites a title link.

## Thresholds

`placeholder_titles`: a list compared case-insensitively (new page, nieuwe pagina, untitled, naamloos, title, titel, page, pagina, test).

Configured on the class, for example:

```yaml
Restruct\Silverstripe\SEO\Checks\Rules\TitleBoilerplateCheck:
  priority: low
```

Disable it with `Restruct\Silverstripe\SEO\Checks\CheckRunner.checks.title-boilerplate.enabled: false`.

Source: https://developers.google.com/search/docs/appearance/title-link
Last reviewed: 2026-11-25
