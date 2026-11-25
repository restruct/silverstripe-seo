<?php

namespace Restruct\Silverstripe\SEO\Tests\Checks;

use Restruct\Silverstripe\SEO\Checks\Check;
use Restruct\Silverstripe\SEO\Checks\CheckRunner;
use Restruct\Silverstripe\SEO\Tests\Stub\Checks\SeoCustomTestCheck;

/**
 * The catalogue as registered in YAML: complete, each check with its spec, and configurable.
 */
class CheckRegistryTest extends ChecksTestCase
{
    /**
     * The design's catalogue: checks 1-21 and 23 (22, the focus keyword, was dropped), with check 13's
     * unpublished targets as a check of their own (13b).
     */
    private const CATALOGUE = [
        'noindex-published', 'title-missing', 'title-boilerplate', 'title-duplicate', 'title-width',
        'description-missing', 'description-duplicate', 'description-length', 'h1-missing', 'heading-structure',
        'image-alt-missing', 'image-alt-quality', 'broken-links', 'unpublished-links', 'link-text', 'link-redirects', 'orphan-page',
        'canonical', 'placeholder-text', 'thin-content', 'structured-data', 'og-image', 'url-segment',
    ];

    public function testTheWholeCatalogueIsRegistered(): void
    {
        $ids = array_keys(CheckRunner::singleton()->getChecks());
        sort($ids);
        $expected = self::CATALOGUE;
        sort($expected);
        $this->assertSame($expected, $ids);
    }

    public function testEveryCheckDescribesItselfConsistently(): void
    {
        foreach (CheckRunner::singleton()->getChecks() as $id => $check) {
            $this->assertInstanceOf(Check::class, $check);
            $this->assertSame($id, $check->id(), 'the YAML key is the id');
            $this->assertContains($check->group(), Check::GROUPS, "$id group");
            $this->assertContains($check->severity(), [Check::PROBLEM, Check::WARNING, Check::TIP], "$id severity");
            $this->assertContains($check->priority(), [Check::HIGH, Check::MEDIUM, Check::LOW], "$id priority");
            $this->assertNotSame('', $check->title(), "$id title");
            $this->assertNotSame('', $check->howToFix(), "$id how to fix");
        }
    }

    public function testEveryCheckHasASpecWithSourceAndReviewDate(): void
    {
        $docs = dirname(__DIR__, 2) . '/docs/checks';
        foreach (self::CATALOGUE as $id) {
            $file = "$docs/$id.md";
            $this->assertFileExists($file);
            $spec = (string) file_get_contents($file);
            $this->assertStringContainsString('Last reviewed: 2026-11-25', $spec, "$id.md review date");
            $this->assertMatchesRegularExpression('#Source: .*https://#', $spec, "$id.md source");
            $this->assertStringContainsString('## Thresholds', $spec, "$id.md thresholds");
        }
        $dropped = (string) file_get_contents(dirname($docs) . '/dropped-checks.md');
        $this->assertStringContainsStringIgnoringCase('focus keyword', $dropped);
        $this->assertStringContainsStringIgnoringCase('keyword density', $dropped);
    }

    public function testACheckCanBeDisabled(): void
    {
        CheckRunner::config()->merge('checks', ['h1-missing' => ['enabled' => false]]);
        $this->assertArrayNotHasKey('h1-missing', $this->results($this->page(['HideH1' => true])));
    }

    public function testAProjectCanAddItsOwnCheck(): void
    {
        CheckRunner::config()->merge('checks', ['custom-draft-title' => ['class' => SeoCustomTestCheck::class]]);
        $this->assertCheckFails($this->page(['Title' => 'Draft courses']), 'custom-draft-title');
        $this->assertCheckPasses($this->page(['Title' => 'Courses']), 'custom-draft-title');
    }

    public function testEntriesCarryTheirMetadataAndADocsLink(): void
    {
        $entry = $this->entry($this->page(['HideH1' => true]), 'h1-missing');
        $this->assertSame('content', $entry['group']);
        $this->assertSame('warning', $entry['severity']);
        $this->assertSame('medium', $entry['priority']);
        $this->assertStringEndsWith('docs/checks/h1-missing.md', $entry['docs']);
    }

    public function testAPageThatCannotBeRenderedReportsThatInsteadOfFalseResults(): void
    {
        $page = $this->page();
        # Rendered as nobody: the draft-only page is refused, as it would be for a visitor
        $results = CheckRunner::singleton()->run($page, null);
        $this->assertSame('fail', $results['render']['status']);
        $this->assertSame('problem', $results['render']['severity']);
        $this->assertSame('skipped', $results['h1-missing']['status'], 'render checks are skipped');
        $this->assertSame('pass', $results['url-segment']['status'], 'field checks still run');
    }
}
