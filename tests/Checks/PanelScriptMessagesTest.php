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
        # The "About this check" dialog
        $this->assertStringContainsString('data-msg-loading="Loading..."', $html);
        $this->assertStringContainsString('data-msg-doc-failed="Could not load the explanation."', $html);
        $this->assertStringContainsString('data-msg-doc-link="Open the documentation"', $html);
        $this->assertStringContainsString('data-msg-close="Close"', $html);
    }

    public function testTheMessagesAreTranslated(): void
    {
        $html = i18n::with_locale('nl_NL', fn () => $this->panelHtml());
        $this->assertStringContainsString('data-msg-not-started="De controle kon niet starten."', $html);
        $this->assertStringContainsString('data-msg-failed="Controle mislukt (HTTP {status})."', $html);
        $this->assertStringContainsString('data-msg-doc-failed="De uitleg kon niet worden geladen."', $html);
        $this->assertStringContainsString('data-msg-close="Sluiten"', $html);
    }

    public function testTheScriptUsesThemAndLogsToTheConsole(): void
    {
        # No browser in this suite: this only pins that the script reads the attributes and logs a failure
        $js = (string) file_get_contents(dirname(__DIR__, 2) . '/client/dist/js/seo-checks.js');
        $this->assertStringContainsString("'data-msg-not-started'", $js);
        $this->assertStringContainsString("'data-msg-failed'", $js);
        $this->assertStringContainsString('console.error', $js);
        $this->assertStringContainsString("'data-msg-doc-failed'", $js);
        $this->assertStringContainsString("'data-doc-url'", $js);
    }

    public function testTheScriptUsesTheAdminsBootstrapOnlyWhenItIsThere(): void
    {
        # The admin's Bootstrap 5 tooltip and modal, through their jQuery bridge; jQuery UI also has a
        # $.fn.tooltip, so the script tells them apart by Bootstrap's Constructor and otherwise leaves the
        # native title and the plain link in place
        $js = (string) file_get_contents(dirname(__DIR__, 2) . '/client/dist/js/seo-checks.js');
        $this->assertStringContainsString('$.fn.tooltip.Constructor', $js);
        $this->assertStringContainsString('$.fn.modal.Constructor', $js);
        $this->assertStringContainsString(".tooltip('dispose')", $js);
        $this->assertStringContainsString("'hidden.bs.modal'", $js);
    }

    public function testTheDialogUsesTheAdminsOwnCloseButton(): void
    {
        # The admin styles its close control by these classes and an icon span (its CloseButton component and
        # its jQuery UI dialogs); a bare Bootstrap .btn-close has its icon switched off in .modal-header
        $js = (string) file_get_contents(dirname(__DIR__, 2) . '/client/dist/js/seo-checks.js');
        $this->assertSame(1, preg_match("#var close = (.*?);\n#s", $js, $m), 'the close button is built in one statement');
        $this->assertStringContainsString("'btn btn-close btn--icon-xl btn--no-text modal__close-button'", $m[1]);
        $this->assertStringContainsString("'btn__icon font-icon-cancel'", $m[1]);
        $this->assertStringContainsString("'aria-hidden': 'true'", $m[1]);
        $this->assertStringContainsString("'data-bs-dismiss': 'modal'", $m[1]);
        $this->assertStringContainsString("'aria-label': this.message('data-msg-close', 'Close')", $m[1]);
        $this->assertStringContainsString("title: this.message('data-msg-close', 'Close')", $m[1]);
    }
}
