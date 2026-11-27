<?php

namespace Restruct\Silverstripe\SEO\Tests\Checks;

use Restruct\Silverstripe\SEO\Checks\CheckRunner;
use Restruct\Silverstripe\SEO\Forms\SeoChecksField;
use Restruct\Silverstripe\SEO\Model\SeoCheckIgnore;
use Restruct\Silverstripe\SEO\Tests\Stub\Checks\SeoDocumentedTestCheck;
use SilverStripe\Admin\LeftAndMain;
use SilverStripe\Core\Manifest\ModuleResourceLoader;
use SilverStripe\i18n\i18n;
use SilverStripe\Security\Security;

/**
 * The controls on a finding: "Ignore for this page" is an icon button at the top right of the finding, "About
 * this check" an icon next to its title, both with the admin's Bootstrap tooltip and an aria-label. The about
 * icon is a link to the published spec, so it still works without the script; for a module check it also names
 * the CMS endpoint that renders the spec in a dialog. The priority is an outlined badge.
 */
class PanelControlsTest extends ChecksTestCase
{
    private function panelHtml($page): string
    {
        $field = $page->getCMSFields()->flattenFields()->fieldByName('SeoChecks');
        $this->assertInstanceOf(SeoChecksField::class, $field);
        return (string) $field->FieldHolder();
    }

    /**
     * The markup of one finding, from its opening tag to the next finding or the end of its list.
     */
    private function finding(string $html, string $id): string
    {
        $this->assertMatchesRegularExpression('#<li[^>]*data-check="' . preg_quote($id, '#') . '"#', $html, $id);
        preg_match('#<li[^>]*data-check="' . preg_quote($id, '#') . '".*?(?=<li[^>]*seo-checks__item|</ul>\s*</div>)#s', $html, $m);
        return $m[0];
    }

    private function tag(string $markup, string $class): string
    {
        $this->assertSame(1, preg_match('#<(?:a|button)\b[^>]*\b' . preg_quote($class, '#') . '\b[^>]*>#', $markup, $m), $class);
        return $m[0];
    }

    private function failingH1Page()
    {
        $page = $this->page(['HideH1' => true]);
        CheckRunner::singleton()->checkPage($page, Security::getCurrentUser());
        return $page;
    }

    public function testIgnoreIsAnIconButtonWithATooltipAtTheTopOfTheFinding(): void
    {
        $finding = $this->finding($this->panelHtml($this->failingH1Page()), 'h1-missing');

        # First in the finding, so it floats to the top right of it
        $this->assertMatchesRegularExpression('#^<li[^>]*>\s*<button[^>]*seo-checks__ignore#', $finding);
        $button = $this->tag($finding, 'seo-checks__ignore');
        $this->assertStringContainsString('font-icon-eye-with-line', $button);
        $this->assertStringContainsString('btn--no-text', $button);
        $this->assertStringContainsString('seo-checks__tip', $button);
        $this->assertStringContainsString('data-bs-toggle="tooltip"', $button);
        $this->assertStringContainsString('aria-label="Ignore for this page"', $button);
        $this->assertStringContainsString('title="Ignore for this page"', $button);
        $this->assertStringContainsString('data-check="h1-missing"', $button);
        $this->assertStringContainsString('data-ignore="1"', $button);
        # No text line of its own: the label is in the tooltip and the aria-label
        $this->assertMatchesRegularExpression('#seo-checks__ignore[^>]*>\s*</button>#', $finding);
    }

    public function testAboutIsAnIconNextToTheTitleLinkingToTheSpec(): void
    {
        $finding = $this->finding($this->panelHtml($this->failingH1Page()), 'h1-missing');

        $this->assertMatchesRegularExpression('#seo-checks__title">[^<]*</strong>\s*<a[^>]*seo-checks__about#', $finding);
        $link = $this->tag($finding, 'seo-checks__about');
        $this->assertStringContainsString('href="https://github.com/restruct/silverstripe-seo/blob/main/docs/checks/h1-missing.md"', $link);
        $this->assertStringContainsString('target="_blank"', $link);
        $this->assertStringContainsString('rel="noopener noreferrer"', $link);
        $this->assertStringContainsString('font-icon-info-circled', $link);
        $this->assertStringContainsString('seo-checks__tip', $link);
        $this->assertStringContainsString('data-bs-toggle="tooltip"', $link);
        $this->assertStringContainsString('aria-label="About this check"', $link);
        $this->assertMatchesRegularExpression('#data-doc-url="[^"]*admin/seo-checks/doc\?check=h1-missing"#', $link);
        $this->assertMatchesRegularExpression('#seo-checks__about[^>]*>\s*</a>#', $finding);

        # Not in the how-to-fix line any more
        preg_match('#<p class="seo-checks__fix">.*?</p>#s', $finding, $fix);
        $this->assertNotEmpty($fix);
        $this->assertStringNotContainsString('About this check', $fix[0]);
    }

    public function testAProjectCheckLinksToItsOwnDocsWithoutTheDialog(): void
    {
        CheckRunner::config()->merge('checks', ['custom-documented' => ['class' => SeoDocumentedTestCheck::class]]);
        $page = $this->page(['Title' => 'Draft of a page']);
        CheckRunner::singleton()->checkPage($page, Security::getCurrentUser());

        $link = $this->tag($this->finding($this->panelHtml($page), 'custom-documented'), 'seo-checks__about');
        $this->assertStringContainsString('href="https://docs.example.test/seo/custom-documented"', $link);
        $this->assertStringNotContainsString('data-doc-url', $link);
    }

    public function testTheLabelsAreTranslated(): void
    {
        $page = $this->failingH1Page();
        $finding = $this->finding(i18n::with_locale('nl_NL', fn () => $this->panelHtml($page)), 'h1-missing');
        $this->assertStringContainsString('aria-label="Negeren voor deze pagina"', $this->tag($finding, 'seo-checks__ignore'));
        $this->assertStringContainsString('aria-label="Over deze controle"', $this->tag($finding, 'seo-checks__about'));
    }

    public function testAnIgnoredFindingHasNoNotMetMarkerEither(): void
    {
        $page = $this->page(['HideH1' => true]);
        SeoCheckIgnore::ignore($page, 'h1-missing', Security::getCurrentUser());
        CheckRunner::singleton()->checkPage($page, Security::getCurrentUser());
        $html = $this->panelHtml($page);
        $this->assertMatchesRegularExpression('#seo-checks__ignored.*data-check="h1-missing"#s', $html);
        $this->assertStringNotContainsString('seo-checks__state--fail', $html);
        # Its "Check again" button keeps its text
        $this->assertMatchesRegularExpression('#seo-checks__ignore[^>]*data-ignore="0"[^>]*>\s*Check again\s*</button>#', $html);
    }

    public function testThePriorityIsAnOutlinedBadge(): void
    {
        $finding = $this->finding($this->panelHtml($this->failingH1Page()), 'h1-missing');
        $this->assertMatchesRegularExpression('#<span class="seo-checks__priority">\s*High priority\s*</span>#', $finding);

        $css = (string) file_get_contents(dirname(__DIR__, 2) . '/client/dist/css/seo-checks.css');
        $this->assertSame(1, preg_match('#\.seo-checks__priority\s*\{([^}]*)\}#', $css, $rule));
        $this->assertMatchesRegularExpression('#border:\s*1px solid#', $rule[1]);
        $this->assertMatchesRegularExpression('#border-radius:#', $rule[1]);
    }

    public function testTheAdminsBootstrapModalIsLoadedInTheCms(): void
    {
        # The admin loads Bootstrap's tooltip itself, not its modal: the panel brings the admin's own copy
        $js = (array) LeftAndMain::config()->get('extra_requirements_javascript');
        $modal = 'silverstripe/admin:thirdparty/bootstrap/js/dist/modal.js';
        $this->assertArrayHasKey($modal, $js);
        $this->assertFileExists(BASE_PATH . '/' . ModuleResourceLoader::singleton()->resolvePath($modal));
        # Before the panel's script, which uses it
        $keys = array_keys($js);
        $this->assertLessThan(
            array_search('restruct/silverstripe-seo:client/dist/js/seo-checks.js', $keys, true),
            array_search($modal, $keys, true)
        );
    }
}
