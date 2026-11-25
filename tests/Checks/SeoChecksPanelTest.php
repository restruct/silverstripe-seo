<?php

namespace Restruct\Silverstripe\SEO\Tests\Checks;

use Restruct\Silverstripe\SEO\Checks\CheckRunner;
use Restruct\Silverstripe\SEO\Checks\Rules\DescriptionLengthCheck;
use Restruct\Silverstripe\SEO\Checks\Rules\TitleWidthCheck;
use Restruct\Silverstripe\SEO\Forms\SeoChecksField;
use Restruct\Silverstripe\SEO\Forms\SeoSnippetPreviewField;
use Restruct\Silverstripe\SEO\Model\SeoCheckIgnore;
use SilverStripe\Admin\LeftAndMain;
use SilverStripe\CMS\Model\RedirectorPage;
use SilverStripe\Core\Convert;
use SilverStripe\Security\Security;

/**
 * The panel in the page's SEO section, as the server renders it (so it works without JavaScript), and the
 * thresholds it hands to the live field layer.
 */
class SeoChecksPanelTest extends ChecksTestCase
{
    private function panel($page): SeoChecksField
    {
        $field = $page->getCMSFields()->dataFieldByName('SeoChecks')
            ?? $page->getCMSFields()->fieldByName('Root.Main.Metadata')?->getChildren()->fieldByName('SeoChecks');
        $this->assertInstanceOf(SeoChecksField::class, $field);
        return $field;
    }

    public function testThePanelIsInTheMetadataSection(): void
    {
        $page = $this->page();
        $metadata = $page->getCMSFields()->fieldByName('Root.Main.Metadata');
        $this->assertInstanceOf(SeoChecksField::class, $metadata->getChildren()->fieldByName('SeoChecks'));
        $this->assertInstanceOf(SeoSnippetPreviewField::class, $metadata->getChildren()->fieldByName('SeoSnippetPreview'));
        # Not a data field: nothing is saved from it
        $this->assertFalse($metadata->getChildren()->fieldByName('SeoChecks')->hasData());
    }

    public function testNoPanelOnARedirectorOrAnUnsavedPage(): void
    {
        $this->assertNull(RedirectorPage::create()->getCMSFields()->fieldByName('Root.Main.Metadata'));
        $unsaved = \Restruct\Silverstripe\SEO\Tests\Stub\Checks\SeoCheckTestPage::create();
        $metadata = $unsaved->getCMSFields()->fieldByName('Root.Main.Metadata');
        $this->assertNull($metadata->getChildren()->fieldByName('SeoChecks'));
    }

    public function testThePanelRendersStoredResultsGroupedWithCountsAndNoScore(): void
    {
        $page = $this->page(['HideH1' => true, 'ShowInMenus' => false, 'Content' => static::GOOD_TEXT . '<p>TODO</p>']);
        CheckRunner::singleton()->checkPage($page, Security::getCurrentUser());

        $html = (string) $this->panel($page)->FieldHolder();
        $this->assertStringContainsString('1 problem', $html);
        $this->assertStringContainsString('2 warnings', $html);
        # Grouped, with message, priority and how to fix, as plain server-side HTML
        $this->assertMatchesRegularExpression('#<h\d[^>]*>\s*Content\s*</h\d>#', $html);
        $this->assertStringContainsString('data-check="placeholder-text"', $html);
        $this->assertStringContainsString('data-check="h1-missing"', $html);
        $this->assertStringContainsString('High priority', $html);
        $this->assertStringContainsString('How to fix', $html);
        $this->assertStringContainsString('TODO', $html);
        $this->assertDoesNotMatchRegularExpression('#score#i', $html);
        $this->assertStringNotContainsString('data-check="url-segment"', $html, 'passed checks are not listed');
    }

    public function testThePanelEscapesCheckOutput(): void
    {
        $page = $this->page(['Content' => static::GOOD_TEXT . '<p><img src="/x.jpg?a=<b>" ></p>']);
        CheckRunner::singleton()->checkPage($page, Security::getCurrentUser());
        $html = (string) $this->panel($page)->FieldHolder();
        $this->assertStringNotContainsString('<b>', $html);
    }

    public function testAnIgnoredCheckIsListedSeparately(): void
    {
        $page = $this->page(['HideH1' => true]);
        SeoCheckIgnore::ignore($page, 'h1-missing', Security::getCurrentUser());
        CheckRunner::singleton()->checkPage($page, Security::getCurrentUser());
        $html = (string) $this->panel($page)->FieldHolder();
        $this->assertMatchesRegularExpression('#seo-checks__ignored.*data-check="h1-missing"#s', $html);
        $this->assertStringContainsString('Ignored by', $html);
    }

    public function testWithoutStoredResultsThePanelAsksForACheck(): void
    {
        $html = (string) $this->panel($this->page())->FieldHolder();
        $this->assertStringContainsString('data-seo-stale="1"', $html);
        $this->assertStringContainsString('Not checked yet', $html);
        $this->assertStringContainsString('data-recheck-url="' . Convert::raw2att('admin/seo-checks/recheck'), $html);
    }

    public function testAStaleResultIsMarkedSo(): void
    {
        $page = $this->page();
        CheckRunner::singleton()->checkPage($page, Security::getCurrentUser());
        $this->assertStringContainsString('data-seo-stale="0"', (string) $this->panel($page)->FieldHolder());
        $page->Title = 'Changed';
        $page->write();
        $html = (string) $this->panel($page)->FieldHolder();
        $this->assertStringContainsString('data-seo-stale="1"', $html);
        $this->assertStringContainsString('changed since', $html);
    }

    public function testTheSnippetPreviewIsRenderedServerSide(): void
    {
        $page = $this->page(['MetaTitle' => 'Pottery for beginners', 'URLSegment' => 'pottery']);
        $html = (string) $page->getCMSFields()->fieldByName('Root.Main.Metadata')->getChildren()
            ->fieldByName('SeoSnippetPreview')->FieldHolder();
        $this->assertStringContainsString('Pottery for beginners | Example Site', $html);
        $this->assertStringContainsString('pottery', $html);
        $this->assertStringContainsString(static::GOOD_DESCRIPTION, $html);
    }

    public function testThresholdsAreExportedFromTheCheckConfig(): void
    {
        TitleWidthCheck::config()->set('max_pixels', 555);
        DescriptionLengthCheck::config()->set('min_length', 33);
        DescriptionLengthCheck::config()->set('max_length', 144);
        $page = $this->page();
        $field = $page->getCMSFields()->fieldByName('Root.Main.Metadata')->getChildren()->fieldByName('SeoSnippetPreview');
        $config = $field->getClientConfig();

        $this->assertSame(555, $config['title']['maxPixels']);
        $this->assertSame(33, $config['description']['min']);
        $this->assertSame(144, $config['description']['max']);
        $this->assertSame(' | ', $config['title']['separator']);
        $this->assertSame('Example Site', $config['title']['suffix']);
        $this->assertArrayHasKey('W', $config['title']['widths']);
        # The same numbers reach the browser, as JSON in an attribute
        $html = (string) $field->FieldHolder();
        $this->assertStringContainsString('data-seo-config="' . Convert::raw2att(json_encode($config)) . '"', $html);
    }

    public function testTheClientFilesAreLoadedInTheCms(): void
    {
        $js = (array) LeftAndMain::config()->get('extra_requirements_javascript');
        $css = (array) LeftAndMain::config()->get('extra_requirements_css');
        $this->assertArrayHasKey('restruct/silverstripe-seo:client/dist/js/seo-checks.js', $js);
        $this->assertArrayHasKey('restruct/silverstripe-seo:client/dist/css/seo-checks.css', $css);
        $base = dirname(__DIR__, 2) . '/client/dist';
        $this->assertFileExists("$base/js/seo-checks.js");
        $this->assertFileExists("$base/css/seo-checks.css");
    }
}
