<?php

namespace Restruct\Silverstripe\SEO\Checks\Rules;

use Restruct\Silverstripe\SEO\Checks\AbstractCheck;
use Restruct\Silverstripe\SEO\Checks\CheckResult;
use Restruct\Silverstripe\SEO\Checks\PageAnalysis;
use Restruct\Silverstripe\SEO\Extensions\SeoSiteTreeExtension;
use SilverStripe\i18n\i18n;
use Throwable;

/**
 * Check 20: structured data that search engines cannot use: a JSON-LD block in the rendered page that is not
 * valid JSON (from a template or another module), or a node of this module's graph that lacks a required
 * property and is therefore left out ({@see SeoSiteTreeExtension::getSchemaWarnings()}). Missing recommended
 * properties are listed in the structured data preview, not here.
 */
class StructuredDataCheck extends AbstractCheck
{
    private static string $id = 'structured-data';

    private static string $group = self::GROUP_STRUCTURED_DATA;

    private static string $severity = self::PROBLEM;

    private static string $priority = self::MEDIUM;

    public function title(): string
    {
        return _t(__CLASS__ . '.Title', 'Structured data is complete');
    }

    public function howToFix(): string
    {
        return _t(
            __CLASS__ . '.HowToFix',
            'Fill in the information listed (for an event, its start date and location, say). Invalid JSON-LD comes from the template or another module: ask the developer.'
        );
    }

    public function run(PageAnalysis $analysis): CheckResult
    {
        $details = [];

        if ($analysis->isRendered()) {
            foreach ($analysis->head()->jsonLd() as $i => $decoded) {
                if ($decoded === null) {
                    $details[] = _t(__CLASS__ . '.InvalidJson', 'JSON-LD block {n} is not valid JSON', ['n' => $i + 1]);
                }
            }
        }

        $page = $analysis->getPage();
        if ($page->hasMethod('getSchemaWarnings') && $page->config()->get('seo_output_structured_data')) {
            try {
                # In the site's language, as the head output is
                $warnings = i18n::with_locale((string) i18n::config()->get('default_locale'), fn () => $page->getSchemaWarnings());
            } catch (Throwable $e) {
                $warnings = [['severity' => 'required', 'message' => $e->getMessage()]];
            }
            foreach ($warnings as $warning) {
                if (($warning['severity'] ?? '') === 'required') {
                    $details[] = (string) $warning['message'];
                }
            }
        }

        if (!$details) {
            return $this->pass();
        }
        return $this->fail(
            _t(__CLASS__ . '.Message', 'Some structured data cannot be used by search engines.'),
            array_values(array_unique($details))
        );
    }
}
