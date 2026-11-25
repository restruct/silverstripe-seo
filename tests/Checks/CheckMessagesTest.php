<?php

namespace Restruct\Silverstripe\SEO\Tests\Checks;

use Restruct\Silverstripe\SEO\Checks\CheckRunner;
use Restruct\Silverstripe\SEO\Forms\SeoChecksField;
use Restruct\Silverstripe\SEO\Model\SeoCheckResult;
use SilverStripe\i18n\i18n;
use SilverStripe\Security\Security;
use SilverStripe\Versioned\Versioned;

/**
 * Stored results hold message keys and parameters, translated when shown: a check run by a Dutch editor reads
 * in English for an English one. Rows stored in the earlier format (finished text) are treated as stale.
 */
class CheckMessagesTest extends ChecksTestCase
{
    public function testResultsAreTranslatedWhenShownNotWhenStored(): void
    {
        $page = $this->page(['HideH1' => true, 'URLSegment' => 'the-best-of-the-courses']);
        i18n::with_locale('nl_NL', fn () => CheckRunner::singleton()->checkPage($page, Security::getCurrentUser()));

        $stored = (string) SeoCheckResult::forPage($page)->Results;
        $this->assertStringNotContainsString('hoofdkop', $stored, 'no Dutch text stored');
        $this->assertStringNotContainsString('kleine woorden', $stored, 'details neither');

        $en = i18n::with_locale('en_US', fn () => (string) SeoChecksField::create('SeoChecks', '', $page)->Field());
        $this->assertStringContainsString('The page has no main heading (H1).', $en);
        $this->assertStringContainsString('small words that add little', $en);
        $this->assertStringContainsString('Main heading (H1)', $en, 'the check title too');

        $nl = i18n::with_locale('nl_NL', fn () => (string) SeoChecksField::create('SeoChecks', '', $page)->Field());
        $this->assertStringContainsString('De pagina heeft geen hoofdkop (H1).', $nl);
        $this->assertStringContainsString('kleine woorden die weinig toevoegen', $nl);
    }

    public function testAnOldFormatRowIsStaleButStillShown(): void
    {
        $page = $this->page();
        SeoCheckResult::create([
            'PageID' => $page->ID,
            'Stage' => Versioned::DRAFT,
            # Later than the page's last edit, so only the format makes it stale
            'CheckedAt' => '2099-01-01 00:00:00',
            'Results' => json_encode(['h1-missing' => [
                'id' => 'h1-missing', 'group' => 'content', 'severity' => 'warning', 'priority' => 'medium',
                'title' => 'Main heading (H1)', 'status' => 'fail', 'message' => 'An old stored message.',
                'details' => [], 'howToFix' => 'Old advice.', 'docs' => '', 'ignored' => false, 'ignoredBy' => null,
            ]]),
            'Warnings' => 1,
        ])->write();

        $field = SeoChecksField::create('SeoChecks', '', $page);
        $this->assertTrue($field->getStale());
        $this->assertStringContainsString('An old stored message.', (string) $field->Field());
    }
}
