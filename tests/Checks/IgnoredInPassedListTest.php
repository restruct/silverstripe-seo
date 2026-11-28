<?php

namespace Restruct\Silverstripe\SEO\Tests\Checks;

use Restruct\Silverstripe\SEO\Checks\CheckRunner;
use Restruct\Silverstripe\SEO\Forms\SeoChecksField;
use Restruct\Silverstripe\SEO\Model\SeoCheckIgnore;
use SilverStripe\i18n\i18n;
use SilverStripe\Security\Security;

/**
 * Ignored checks are listed inside the folded list of passed checks, not in a block of their own (product
 * owner's call, 2026-11-28): the summary counts both ("18 checks OK, 2 ignored"), and each ignored check has an
 * icon button to check it again, styled like the ignore button, with the admin's Bootstrap tooltip.
 */
class IgnoredInPassedListTest extends ChecksTestCase
{
    private function panelHtml($page): string
    {
        $field = $page->getCMSFields()->flattenFields()->fieldByName('SeoChecks');
        $this->assertInstanceOf(SeoChecksField::class, $field);
        return (string) $field->FieldHolder();
    }

    private function ignoredPage()
    {
        # Two failing checks, both ignored
        $page = $this->page(['HideH1' => true, 'Content' => static::GOOD_TEXT . '<p>TODO</p>']);
        SeoCheckIgnore::ignore($page, 'h1-missing', Security::getCurrentUser());
        SeoCheckIgnore::ignore($page, 'placeholder-text', Security::getCurrentUser());
        CheckRunner::singleton()->checkPage($page, Security::getCurrentUser());
        return $page;
    }

    /**
     * The folded list of passed checks, from its opening tag to its end.
     */
    private function passedList(string $html): string
    {
        $this->assertSame(1, preg_match('#<details class="seo-checks__passed">.*?</details>#s', $html, $m), 'the passed list');
        return $m[0];
    }

    public function testIgnoredChecksAreInThePassedListAndNotInABlockOfTheirOwn(): void
    {
        $html = $this->panelHtml($this->ignoredPage());
        $this->assertStringNotContainsString('seo-checks__ignored"', $html);
        $this->assertStringNotContainsString('Ignored for this page', $html);

        $passed = $this->passedList($html);
        $this->assertMatchesRegularExpression('#<li[^>]*seo-checks__item--ignored[^>]*data-check="h1-missing"#', $passed);
        $this->assertMatchesRegularExpression('#<li[^>]*seo-checks__item--ignored[^>]*data-check="placeholder-text"#', $passed);
        # Still says who ignored it
        $this->assertStringContainsString('Ignored by', $passed);
        # Not listed among the failures either: no ignore button for them (other checks, the sharing image say,
        # may still fail on this page)
        $this->assertStringNotContainsString('data-check="h1-missing" data-ignore="1"', $html);
        $this->assertStringNotContainsString('data-check="placeholder-text" data-ignore="1"', $html);
        $this->assertStringContainsString('data-ignore="1"', $html, 'control: the failures do have ignore buttons');
    }

    public function testTheSummaryCountsPassedAndIgnoredChecks(): void
    {
        $page = $this->ignoredPage();
        $passedCount = $this->panel($page)->getPassedCount();
        $this->assertGreaterThan(1, $passedCount);

        preg_match('#<summary>(.*?)</summary>#s', $this->passedList($this->panelHtml($page)), $summary);
        $this->assertSame("$passedCount checks OK, 2 ignored", trim($summary[1]));

        $nl = i18n::with_locale('nl_NL', fn () => $this->panelHtml($page));
        preg_match('#<summary>(.*?)</summary>#s', $this->passedList($nl), $summary);
        $this->assertSame("$passedCount controles OK, 2 genegeerd", trim($summary[1]));
    }

    public function testWithoutIgnoredChecksTheSummaryOnlyCountsThePassedOnes(): void
    {
        $page = $this->page();
        CheckRunner::singleton()->checkPage($page, Security::getCurrentUser());
        $passedCount = $this->panel($page)->getPassedCount();
        preg_match('#<summary>(.*?)</summary>#s', $this->passedList($this->panelHtml($page)), $summary);
        $this->assertSame("$passedCount checks OK", trim($summary[1]));
    }

    public function testTheFieldCountsTheIgnoredChecks(): void
    {
        # The passed list is shown when there is anything to fold away, passed or ignored
        $page = $this->ignoredPage();
        $field = $this->panel($page);
        $this->assertCount(2, $field->getIgnored());
        $this->assertSame(2, $field->getIgnoredCount());
    }

    public function testAnIgnoredCheckHasAnUnignoreIconButtonLikeTheIgnoreButton(): void
    {
        $passed = $this->passedList($this->panelHtml($this->ignoredPage()));
        preg_match('#<li[^>]*data-check="h1-missing".*?</li>#s', $passed, $item);
        $this->assertNotEmpty($item);

        # First in the item, so it floats to the top right as the ignore button does
        $this->assertMatchesRegularExpression('#^<li[^>]*>\s*<button[^>]*seo-checks__ignore#', $item[0]);
        $this->assertSame(1, preg_match('#<button\b[^>]*seo-checks__ignore[^>]*>#', $item[0], $button));
        $button = $button[0];
        $this->assertStringContainsString('font-icon-eye', $button);
        $this->assertStringNotContainsString('font-icon-eye-with-line', $button);
        $this->assertStringContainsString('btn--no-text', $button);
        $this->assertStringContainsString('seo-checks__ignore--icon', $button);
        $this->assertStringContainsString('seo-checks__tip', $button);
        $this->assertStringContainsString('data-bs-toggle="tooltip"', $button);
        $this->assertStringContainsString('data-check="h1-missing"', $button);
        $this->assertStringContainsString('data-ignore="0"', $button);
        $this->assertStringContainsString('title="Check again for this page"', $button);
        $this->assertStringContainsString('aria-label="Check again for this page"', $button);
        # No text of its own: the label is in the tooltip and the aria-label
        $this->assertMatchesRegularExpression('#seo-checks__ignore[^>]*>\s*</button>#', $item[0]);
    }

    public function testTheUnignoreLabelIsTranslated(): void
    {
        $page = $this->ignoredPage();
        $html = i18n::with_locale('nl_NL', fn () => $this->panelHtml($page));
        $this->assertStringContainsString('aria-label="Weer controleren voor deze pagina"', $html);
        $this->assertStringContainsString('Genegeerd door', $html);
    }

    private function panel($page): SeoChecksField
    {
        $field = $page->getCMSFields()->flattenFields()->fieldByName('SeoChecks');
        $this->assertInstanceOf(SeoChecksField::class, $field);
        return $field;
    }
}
