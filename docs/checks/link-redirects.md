# link-redirects: Links go straight to their target

Check 15 of the catalogue. Severity: tip. Priority: low. Group: links. Reads the rendered page: yes.
Class: `Restruct\Silverstripe\SEO\Checks\Rules\LinkRedirectsCheck`.

Heading in the panel: "Links go straight to their target" when it passes, "Links go through a redirect" when it fails.

## What it checks

Links in the content area to a redirector page, and `http://` links while the site runs on https.

## Why

Every redirect is a detour for visitors and crawlers; an http link on an https site leaves the secure connection or costs a redirect.

## Thresholds

`max_listed`: 10.

Configured on the class, for example:

```yaml
Restruct\Silverstripe\SEO\Checks\Rules\LinkRedirectsCheck:
  priority: low
```

Disable it with `Restruct\Silverstripe\SEO\Checks\CheckRunner.checks.link-redirects.enabled: false`.

Source: https://developers.google.com/search/docs/crawling-indexing/301-redirects
Last reviewed: 2026-11-25
