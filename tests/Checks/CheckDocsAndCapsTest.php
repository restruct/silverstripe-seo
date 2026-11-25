<?php

namespace Restruct\Silverstripe\SEO\Tests\Checks;

use Restruct\Silverstripe\SEO\Checks\AbstractCheck;
use Restruct\Silverstripe\SEO\Checks\CheckRunner;
use Restruct\Silverstripe\SEO\Tests\Stub\Checks\SeoCustomTestCheck;
use Restruct\Silverstripe\SEO\Tests\Stub\Checks\SeoDocumentedTestCheck;
use SilverStripe\Security\Security;

/**
 * "About this check" links to the module's docs only for the module's own checks; a check can bring its own.
 * Every list of details is capped (max_listed, default 20) and says how many more there are.
 */
class CheckDocsAndCapsTest extends ChecksTestCase
{
    private function entries($page): array
    {
        return CheckRunner::singleton()->run($page, Security::getCurrentUser());
    }

    public function testDocsLinkOnlyForModuleChecksUnlessACheckBringsItsOwn(): void
    {
        CheckRunner::config()->merge('checks', [
            'custom-draft-title' => ['class' => SeoCustomTestCheck::class],
            'custom-documented' => ['class' => SeoDocumentedTestCheck::class],
        ]);
        $entries = $this->entries($this->page());
        $this->assertStringContainsString('docs/checks/h1-missing.md', $entries['h1-missing']['docs']);
        $this->assertSame('', $entries['custom-draft-title']['docs']);
        $this->assertSame('https://docs.example.test/seo/custom-documented', $entries['custom-documented']['docs']);
    }

    public function testTheDefaultCapIsTwentyWithTheRestCounted(): void
    {
        $this->assertSame(20, AbstractCheck::config()->get('max_listed'));
        $links = '';
        for ($i = 1; $i <= 25; $i++) {
            $links .= '<a href="/no-such-page-' . $i . '">Page number ' . $i . '</a> ';
        }
        $entry = $this->entries($this->page(['Content' => static::GOOD_TEXT . '<p>' . $links . '</p>']))['broken-links'];
        $this->assertCount(21, $entry['details']);
        $this->assertSame('and 5 more', end($entry['details']));
    }

    public function testHeadingStructureIsCappedToo(): void
    {
        $headings = str_repeat('<h2>Section</h2><h4>Skipped a level</h4>', 24);
        $entry = $this->entries($this->page(['Content' => static::GOOD_TEXT . $headings]))['heading-structure'];
        $this->assertCount(21, $entry['details']);
        $this->assertSame('and 4 more', end($entry['details']));
    }

    public function testDuplicateListsCountTheRest(): void
    {
        for ($i = 0; $i < 22; $i++) {
            $this->page(['Title' => 'Same title'], [], true);
        }
        $entry = $this->entries($this->page(['Title' => 'Same title']))['title-duplicate'];
        $this->assertCount(21, $entry['details']);
        $this->assertSame('and 2 more', end($entry['details']));
    }
}
