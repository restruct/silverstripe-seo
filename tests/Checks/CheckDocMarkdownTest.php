<?php

namespace Restruct\Silverstripe\SEO\Tests\Checks;

use Restruct\Silverstripe\SEO\Text\DocMarkdown;
use SilverStripe\Dev\SapphireTest;

/**
 * The Markdown renderer behind the panel's "About this check" dialog (league/commonmark, configured by
 * DocMarkdown): what the check specs in docs/checks/ use, and nothing of the source passes through as HTML.
 */
class CheckDocMarkdownTest extends SapphireTest
{
    private function render(string $markdown): array
    {
        return DocMarkdown::create()->render($markdown);
    }

    public function testTheFirstHeadingIsTheTitleAndTheRestIsTheBody(): void
    {
        $doc = $this->render("# h1-missing: Page has a main heading\n\nIntro line one\nand line two.\n\n## Why\n\nBecause.");
        $this->assertSame('h1-missing: Page has a main heading', $doc['title']);
        $this->assertStringNotContainsString('Page has a main heading', $doc['html']);
        # Lines of one paragraph are joined; a level-2 heading sits below the dialog's own title
        $this->assertStringContainsString('<p>Intro line one and line two.</p>', $doc['html']);
        $this->assertStringContainsString('<h3>Why</h3>', $doc['html']);
        $this->assertStringContainsString('<p>Because.</p>', $doc['html']);
    }

    public function testInlineCodeFencedCodeAndEmphasis(): void
    {
        $doc = $this->render("Use `max_pixels` and **not** `**this**`.\n\n```yaml\nA\\B:\n  priority: low\n```");
        $this->assertStringContainsString('<code>max_pixels</code>', $doc['html']);
        $this->assertStringContainsString('<strong>not</strong>', $doc['html']);
        # Nothing is formatted inside a code span
        $this->assertStringContainsString('<code>**this**</code>', $doc['html']);
        $this->assertStringContainsString("<pre><code class=\"language-yaml\">A\\B:\n  priority: low\n</code></pre>", $doc['html']);
    }

    public function testListsAndLinks(): void
    {
        $doc = $this->render("- one\n- two\n\n1. first\n2. second\n\nSee [the guide](https://example.test/guide), "
            . "or https://example.test/plain.");
        $this->assertStringContainsString("<ul>\n<li>one</li>\n<li>two</li>\n</ul>", $doc['html']);
        $this->assertStringContainsString("<ol>\n<li>first</li>\n<li>second</li>\n</ol>", $doc['html']);
        $this->assertStringContainsString(
            '<a rel="noopener noreferrer" target="_blank" href="https://example.test/guide">the guide</a>',
            $doc['html']
        );
        # A bare URL is linked without the full stop after it
        $this->assertStringContainsString(
            '<a rel="noopener noreferrer" target="_blank" href="https://example.test/plain">https://example.test/plain</a>.',
            $doc['html']
        );
    }

    public function testNoRawHtmlPassesThrough(): void
    {
        $doc = $this->render("# Title <img src=x onerror=alert(1)>\n\n"
            . "Text <script>alert(1)</script> and <b>bold</b>.\n\n"
            . "<div onclick=\"alert(1)\">block</div>\n\n"
            . "[click](javascript:alert(1)) [data](data:text/html,x)\n\n"
            . "https://example.test/\"onmouseover=\"alert(1)\n\n"
            . "```\n<script>alert(2)</script>\n```");
        $all = $doc['title'] . $doc['html'];
        foreach (['<script', '<img', '<b>', '<div', 'javascript:', 'data:text', '"onmouseover'] as $raw) {
            $this->assertStringNotContainsString($raw, $all, $raw);
        }
        # Escaped, not dropped: the text is still there to read
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $doc['html']);
        $this->assertStringContainsString('&lt;script&gt;alert(2)&lt;/script&gt;', $doc['html']);
        $this->assertStringContainsString('Title &lt;img', $doc['title']);
        # A link to anything but http(s) keeps its text and loses the link
        $this->assertStringContainsString('click', $doc['html']);
        $this->assertStringNotContainsString('<a href="javascript', $doc['html']);
    }

    public function testEveryCheckSpecRenders(): void
    {
        $files = glob(dirname(__DIR__, 2) . '/docs/checks/*.md');
        $this->assertNotEmpty($files);
        foreach ($files as $file) {
            $doc = $this->render((string) file_get_contents($file));
            $id = basename($file, '.md');
            $this->assertStringStartsWith($id . ': ', $doc['title'], $id);
            $this->assertStringContainsString('<h3>What it checks</h3>', $doc['html'], $id);
            $this->assertStringContainsString('<h3>Why</h3>', $doc['html'], $id);
        }
    }
}
