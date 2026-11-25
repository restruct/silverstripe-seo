<?php

namespace Restruct\Silverstripe\SEO\Tests\Checks;

use Restruct\Silverstripe\SEO\Checks\Rules\TitleWidthCheck;
use Restruct\Silverstripe\SEO\Extensions\SeoSiteTreeExtension;
use Restruct\Silverstripe\SEO\Text\TitleWidth;
use Restruct\Silverstripe\SEO\Tests\Stub\Checks\SeoCheckTestPage;
use SilverStripe\ORM\DB;

/**
 * Checks 2-5: the title as the rendered head has it.
 */
class TitleChecksTest extends ChecksTestCase
{
    public function testTitleMissingPassesWithARenderedTitle(): void
    {
        $this->assertCheckPasses($this->page(), 'title-missing');
    }

    public function testTitleMissingFailsWhenTheTitleIsEmpty(): void
    {
        # No page title, no meta title and no suffix: the head has an empty <title>
        SeoCheckTestPage::config()->set('seo_title_append_site_name', false);
        $page = $this->page();
        # Straight to the table: core gives a page without a title a default one on write
        DB::prepared_query('UPDATE "SiteTree" SET "Title" = \'\' WHERE "ID" = ?', [$page->ID]);
        $this->assertCheckFails($page, 'title-missing');
    }

    public function testBoilerplatePassesForAnOrdinaryTitle(): void
    {
        $this->assertCheckPasses($this->page(['MetaTitle' => 'Courses for grown-ups']), 'title-boilerplate');
    }

    public function testBoilerplateFailsWhenTheTitleIsTheSiteName(): void
    {
        $this->assertCheckFails($this->page(['MetaTitle' => 'Example Site']), 'title-boilerplate');
    }

    public function testBoilerplateFailsWhenTheSiteNameIsRepeated(): void
    {
        # A separator the suffix logic does not recognise as one, so the site name ends up in it twice
        $page = $this->page(['MetaTitle' => 'Example Site courses']);
        $this->assertCheckFails($page, 'title-boilerplate', 'Example Site');
    }

    public function testBoilerplateFailsForAPlaceholderTitle(): void
    {
        $this->assertCheckFails($this->page(['Title' => 'New Page']), 'title-boilerplate');
        $this->assertCheckFails($this->page(['Title' => 'Nieuwe pagina']), 'title-boilerplate');
    }

    public function testDuplicatePassesForAUniqueTitle(): void
    {
        $this->page(['Title' => 'Something else']);
        $this->assertCheckPasses($this->page(['Title' => 'Unique title']), 'title-duplicate');
    }

    public function testDuplicateFailsAndNamesTheOtherPage(): void
    {
        # The other page's title comes from its page title, this one's from the meta title: same result
        $this->page(['Title' => 'Pottery for beginners', 'URLSegment' => 'pottery']);
        $page = $this->page(['Title' => 'Pottery', 'MetaTitle' => 'Pottery for beginners']);
        $this->assertCheckFails($page, 'title-duplicate', 'pottery');
    }

    public function testDuplicateIgnoresNoindexPages(): void
    {
        $this->page(['Title' => 'Pottery for beginners', 'MetaRobotsNoIndex' => true]);
        $this->assertCheckPasses($this->page(['MetaTitle' => 'Pottery for beginners']), 'title-duplicate');
    }

    public function testWidthPassesForAShortTitle(): void
    {
        $this->assertCheckPasses($this->page(['MetaTitle' => 'Courses']), 'title-width');
    }

    public function testWidthFailsForALongTitle(): void
    {
        $page = $this->page(['MetaTitle' => str_repeat('Wide Words With Many Capitals ', 4)]);
        $entry = $this->assertCheckFails($page, 'title-width');
        $this->assertStringContainsString('px', $entry['message']);
    }

    public function testWidthThresholdIsConfigurable(): void
    {
        TitleWidthCheck::config()->set('max_pixels', 100);
        $this->assertCheckFails($this->page(['MetaTitle' => 'Courses for adults']), 'title-width');
    }

    public function testTitleWidthIsApproximatedPerCharacter(): void
    {
        $width = TitleWidth::singleton();
        # A narrow letter is narrower than a wide one, and an accented letter counts as its base letter
        $this->assertLessThan($width->pixels('WWWW'), $width->pixels('iiii'));
        $this->assertSame($width->pixels('e'), $width->pixels('é'));
        $this->assertGreaterThan(0, $width->pixels('?'));
    }

    public function testDefaultSeparatorStaysTheModuleOne(): void
    {
        # Control for the boilerplate test: the title is built with the module's separator
        $this->assertSame(' | ', SeoCheckTestPage::config()->get('seo_title_separator'));
        $this->assertTrue(SeoCheckTestPage::has_extension(SeoSiteTreeExtension::class));
    }
}
