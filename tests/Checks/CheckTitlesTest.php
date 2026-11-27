<?php

namespace Restruct\Silverstripe\SEO\Tests\Checks;

use Restruct\Silverstripe\SEO\Checks\CheckResult;
use Restruct\Silverstripe\SEO\Checks\CheckRunner;
use Restruct\Silverstripe\SEO\Forms\SeoChecksField;
use Restruct\Silverstripe\SEO\Model\SeoCheckResult;
use SilverStripe\i18n\i18n;
use SilverStripe\Security\Security;
use SilverStripe\Versioned\Versioned;

/**
 * A check's heading says what was found, not what the check is after: "Title is not unique" for a failure,
 * "Title is unique" for a pass, with a status marker next to the severity badge. The goal title ("Title is
 * unique") is what a skipped check and a stored row of a removed check show.
 */
class CheckTitlesTest extends ChecksTestCase
{
    private function panelHtml($page): string
    {
        # Wherever the panel sits in the form
        $field = $page->getCMSFields()->flattenFields()->fieldByName('SeoChecks');
        $this->assertInstanceOf(SeoChecksField::class, $field);
        return (string) $field->FieldHolder();
    }

    public function testEveryCheckHasAPassAndAFailTitleInEnglishAndDutch(): void
    {
        $runner = CheckRunner::singleton();
        $ids = array_merge([CheckRunner::RENDER_ENTRY], array_keys($runner->getChecks()));
        $this->assertCount(24, $ids, 'the 23 checks and the render entry');

        $titles = [];
        foreach (['en_US', 'nl_NL'] as $locale) {
            i18n::with_locale($locale, function () use ($runner, $ids, $locale, &$titles) {
                foreach ($ids as $id) {
                    $pass = $runner->displayTitle($id, CheckResult::PASS);
                    $fail = $runner->displayTitle($id, CheckResult::FAIL);
                    $this->assertNotSame('', $pass, "$id pass title ($locale)");
                    $this->assertNotSame('', $fail, "$id fail title ($locale)");
                    $this->assertNotSame($pass, $fail, "$id: a failure must not read like a pass ($locale)");
                    $titles[$locale][$id] = [$pass, $fail];
                }
            });
        }
        foreach ($ids as $id) {
            $this->assertNotSame($titles['en_US'][$id][1], $titles['nl_NL'][$id][1], "$id fail title is translated");
        }
        $this->assertSame(['Title is unique', 'Title is not unique'], $titles['en_US']['title-duplicate']);
        $this->assertSame(['Titel is uniek', 'Titel is niet uniek'], $titles['nl_NL']['title-duplicate']);
    }

    public function testAFailingCheckShowsItsFailTitleAndANotMetMarker(): void
    {
        $this->page(['Title' => 'Pottery for beginners'], [], true);
        $page = $this->page(['Title' => 'Pottery for beginners']);
        CheckRunner::singleton()->checkPage($page, Security::getCurrentUser());

        $html = $this->panelHtml($page);
        $this->assertMatchesRegularExpression(
            '#data-check="title-duplicate".*?seo-checks__state--fail[^>]*>\s*Not met\s*<.*?seo-checks__title">\s*Title is not unique\s*<#s',
            $html
        );
    }

    public function testPassedChecksAreListedWithTheirPassTitleAndAnOkMarker(): void
    {
        $page = $this->page();
        CheckRunner::singleton()->checkPage($page, Security::getCurrentUser());

        $html = $this->panelHtml($page);
        $this->assertMatchesRegularExpression(
            '#seo-checks__passed.*?seo-checks__state--pass[^>]*>\s*OK\s*<.*?Title is unique#s',
            $html
        );
        $this->assertStringNotContainsString('Title is not unique', $html);
    }

    public function testASkippedCheckIsMarkedNotChecked(): void
    {
        # Redirects elsewhere: the checks that read the render are skipped
        $page = $this->page(['RedirectTo' => '/somewhere-else/']);
        CheckRunner::singleton()->checkPage($page, Security::getCurrentUser());

        $html = $this->panelHtml($page);
        $this->assertMatchesRegularExpression(
            '#seo-checks__skipped.*?seo-checks__state--skipped[^>]*>\s*Not checked\s*<.*?Main heading \(H1\)#s',
            $html
        );
        # The render entry itself is a failure, with its fail title
        $this->assertStringContainsString('The page does not render', $html);
    }

    public function testTheDutchPanelSaysNietGehaald(): void
    {
        $this->page(['Title' => 'Pottery for beginners'], [], true);
        $page = $this->page(['Title' => 'Pottery for beginners']);
        CheckRunner::singleton()->checkPage($page, Security::getCurrentUser());

        $html = i18n::with_locale('nl_NL', fn () => $this->panelHtml($page));
        $this->assertStringContainsString('Niet gehaald', $html);
        $this->assertStringContainsString('Titel is niet uniek', $html);
    }

    public function testAStoredRowOfARemovedCheckShowsItsStoredTitle(): void
    {
        $page = $this->page();
        # An entry of a check that is no longer registered, in the stored format: its goal title is all there is
        SeoCheckResult::store($page, Versioned::DRAFT, [
            'gone-check' => [
                'id' => 'gone-check',
                'group' => 'content',
                'severity' => 'warning',
                'priority' => 'low',
                'title' => ['_t' => 'Gone.Title', 'default' => 'Old goal title', 'params' => []],
                'status' => CheckResult::FAIL,
                'message' => 'Something was wrong.',
                'details' => [],
                'howToFix' => '',
                'docs' => '',
                'ignored' => false,
                'ignoredBy' => null,
            ],
        ]);

        $html = $this->panelHtml($page);
        $this->assertMatchesRegularExpression('#seo-checks__state--fail.*?Old goal title#s', $html);
    }
}
