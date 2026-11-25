<?php

namespace Restruct\Silverstripe\SEO\Tests\Checks;

/**
 * Checks 9, 10, 18 and 19: headings, placeholder text and the amount of text, on the rendered page.
 */
class ContentChecksTest extends ChecksTestCase
{
    public function testH1PassesWithTheTemplateH1(): void
    {
        $this->assertCheckPasses($this->page(), 'h1-missing');
    }

    public function testH1FailsWithoutOne(): void
    {
        $this->assertCheckFails($this->page(['HideH1' => true]), 'h1-missing');
    }

    public function testH1InABlockCounts(): void
    {
        $page = $this->page(['HideH1' => true, 'Content' => ''], [
            ['Title' => 'Intro', 'Content' => '<h1>Courses</h1>' . static::GOOD_TEXT],
        ]);
        $this->assertCheckPasses($page, 'h1-missing');
    }

    public function testHeadingStructurePassesForOrderedHeadings(): void
    {
        $page = $this->page(['Content' => '<h2>Courses</h2>' . static::GOOD_TEXT . '<h3>Languages</h3><p>Text.</p>']);
        # The nav's <h4> sits outside <main> and is not part of the content outline
        $this->assertCheckPasses($page, 'heading-structure');
    }

    public function testHeadingStructureFailsForTwoH1s(): void
    {
        $page = $this->page(['Content' => '<h1>Another main heading</h1>' . static::GOOD_TEXT]);
        $this->assertCheckFails($page, 'heading-structure', 'h1');
    }

    public function testHeadingStructureFailsForASkippedLevel(): void
    {
        $page = $this->page([], [
            ['Title' => 'Languages', 'Content' => '<h4>Spanish</h4><p>Text.</p>'],
        ]);
        $this->assertCheckFails($page, 'heading-structure', 'Spanish');
    }

    public function testPlaceholderPassesForOrdinaryText(): void
    {
        # The template's footer has "Lorem ipsum" outside <main>: not content, not reported
        $this->assertCheckPasses($this->page(), 'placeholder-text');
    }

    public function testPlaceholderFailsForLoremIpsumAndTodo(): void
    {
        $this->assertCheckFails(
            $this->page(['Content' => static::GOOD_TEXT . '<p>Lorem ipsum dolor sit amet.</p>']),
            'placeholder-text',
            'lorem ipsum'
        );
        $this->assertCheckFails(
            $this->page([], [['Title' => 'Prices', 'Content' => '<p>TODO: add the prices</p>']]),
            'placeholder-text',
            'TODO'
        );
    }

    public function testPlaceholderLooksAtTheDescriptionToo(): void
    {
        $this->assertCheckFails($this->page(['MetaDescription' => 'xxx']), 'placeholder-text', 'xxx');
    }

    public function testPlaceholderDoesNotMatchInsideWords(): void
    {
        # "todos" and "XXXL" are words, not placeholders
        $page = $this->page(['Content' => static::GOOD_TEXT . '<p>Sizes up to XXXL. Mis todos amigos.</p>']);
        $this->assertCheckPasses($page, 'placeholder-text');
    }

    public function testThinContentPassesWithEnoughText(): void
    {
        $this->assertCheckPasses($this->page(), 'thin-content');
    }

    public function testThinContentIsInformationOnly(): void
    {
        $entry = $this->assertCheckFails($this->page(['Content' => '<p>Call us.</p>']), 'thin-content');
        $this->assertSame('tip', $entry['severity']);
        $this->assertSame('low', $entry['priority']);
    }

    public function testThinContentCountsBlockText(): void
    {
        $page = $this->page(['Content' => ''], [['Title' => 'About', 'Content' => static::GOOD_TEXT]]);
        $this->assertCheckPasses($page, 'thin-content');
    }
}
