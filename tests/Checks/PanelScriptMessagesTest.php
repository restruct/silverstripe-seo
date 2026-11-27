<?php

namespace Restruct\Silverstripe\SEO\Tests\Checks;

use Restruct\Silverstripe\SEO\Forms\SeoChecksField;
use SilverStripe\i18n\i18n;

/**
 * What the panel's script says when a check cannot start (no endpoint URL, no page ID or no security token in
 * the form) or the request fails: translated on the server and handed to the script in data attributes, so a
 * click on Re-check never ends in silence.
 */
class PanelScriptMessagesTest extends ChecksTestCase
{
    private function panelHtml(): string
    {
        $field = $this->page()->getCMSFields()->flattenFields()->fieldByName('SeoChecks');
        $this->assertInstanceOf(SeoChecksField::class, $field);
        return (string) $field->FieldHolder();
    }

    public function testThePanelCarriesTheScriptsMessages(): void
    {
        $html = $this->panelHtml();
        $this->assertStringContainsString('data-msg-checking="Checking..."', $html);
        $this->assertStringContainsString('data-msg-not-started="Could not start the check."', $html);
        $this->assertStringContainsString('data-msg-failed="Check failed (HTTP {status})."', $html);
    }

    public function testTheMessagesAreTranslated(): void
    {
        $html = i18n::with_locale('nl_NL', fn () => $this->panelHtml());
        $this->assertStringContainsString('data-msg-not-started="De controle kon niet starten."', $html);
        $this->assertStringContainsString('data-msg-failed="Controle mislukt (HTTP {status})."', $html);
    }

    public function testTheScriptUsesThemAndLogsToTheConsole(): void
    {
        # No browser in this suite: this only pins that the script reads the attributes and logs a failure
        $js = (string) file_get_contents(dirname(__DIR__, 2) . '/client/dist/js/seo-checks.js');
        $this->assertStringContainsString("'data-msg-not-started'", $js);
        $this->assertStringContainsString("'data-msg-failed'", $js);
        $this->assertStringContainsString('console.error', $js);
    }
}
