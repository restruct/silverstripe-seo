<?php

namespace Restruct\Silverstripe\SEO\Tests;

use Restruct\Silverstripe\SEO\Text\DescriptionSummary;
use SilverStripe\Dev\SapphireTest;

/**
 * The plain-text description derived from field HTML: where words are separated, how entities are decoded and
 * which bracketed text counts as a shortcode. Runs inside SapphireTest because the shortcodes registered by core
 * modules (`image`, `sitetree_link`, `file_link`) are set up when the kernel boots.
 */
class DescriptionSummaryTest extends SapphireTest
{
    private function summary(string $html): string
    {
        return DescriptionSummary::fromHtml($html, 155);
    }

    public function testLineBreakSeparatesWords(): void
    {
        $this->assertSame('Line one Line two', $this->summary('<p>Line one<br>Line two</p>'));
        $this->assertSame('Line one Line two', $this->summary('<p>Line one<br />Line two</p>'));
    }

    public function testBlockElementsSeparateWords(): void
    {
        # Without paragraphs the whole text is used; adjacent blocks must not run their words together
        $this->assertSame('First block Second block', $this->summary('<div>First block</div><div>Second block</div>'));
        $this->assertSame('Title Item one Item two', $this->summary('<h2>Title</h2><ul><li>Item one</li><li>Item two</li></ul>'));
    }

    public function testEntitiesAreDecodedExactlyOnce(): void
    {
        # The editor typed "&lt;b&gt;" as text; the field stores it escaped once more. One decode gives back
        # what the reader saw, a second one would turn it into a tag-like "<b>"
        $this->assertSame('Use &lt;b&gt; for bold', $this->summary('<p>Use &amp;lt;b&amp;gt; for bold</p>'));
        $this->assertSame('Use &lt;b&gt; for bold', $this->summary('<div>Use &amp;lt;b&amp;gt; for bold</div>'));
        $this->assertSame('Fish & chips', $this->summary('<p>Fish &amp; chips</p>'));
        $this->assertSame('Fish & chips', $this->summary('<div>Fish &amp; chips</div>'));
    }

    public function testOnlyRegisteredShortcodesAreRemoved(): void
    {
        $this->assertSame(
            'As written [sic] in the report [1].',
            $this->summary('<p>As written [sic] in the report [1].</p>')
        );
        # Registered ones go, including one inside an attribute
        $this->assertSame(
            'Read the guide first.',
            $this->summary('<p>[image src="x.jpg" id="3"]Read the <a href="[sitetree_link,id=1]">guide</a> first.</p>')
        );
    }

    public function testPlainTextKeepsALineBreakAtEveryBlockBoundary(): void
    {
        $this->assertSame(
            "One two\nA\nB\nline\nbreak",
            DescriptionSummary::plainText('<p>One <b>two</b></p><ul><li>A</li><li>B</li></ul>line<br>break')
        );
        # A line break in the HTML source is no break on the page
        $this->assertSame('Line one', DescriptionSummary::plainText("<p>Line\n   one</p>"));
        $this->assertSame('', DescriptionSummary::plainText('<p> </p>'));
    }
}
