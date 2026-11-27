<?php

namespace Restruct\Silverstripe\SEO\Text;

use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\Autolink\AutolinkExtension;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\CommonMark\Node\Block\Heading;
use League\CommonMark\Extension\ExternalLink\ExternalLinkExtension;
use League\CommonMark\Node\Block\Document;
use League\CommonMark\Parser\MarkdownParser;
use League\CommonMark\Renderer\HtmlRenderer;
use SilverStripe\Core\Injector\Injectable;

/**
 * Renders a check spec (docs/checks/<id>.md) for the panel's "About this check" dialog, with league/commonmark.
 *
 * Configured so nothing of the source passes through as HTML: raw HTML in the Markdown is escaped
 * (`html_input: escape`), and links with an unsafe scheme (javascript:, most data:) lose their href
 * (`allow_unsafe_links: false`). Bare URLs are linked (the specs end with one). Every link opens in a new tab
 * with rel="noopener noreferrer", as the dialog sits inside the edit form.
 *
 * On top of the plain conversion: the first `# ` heading is the dialog's title, taken out of the body, and the
 * other headings move down a level (`##` becomes `<h3>`), as the dialog's own title sits above them.
 */
class DocMarkdown
{
    use Injectable;

    /**
     * The first level-1 heading as escaped text, and the rest as HTML.
     *
     * @return array{title: string, html: string}
     */
    public function render(string $markdown): array
    {
        $environment = new Environment([
            'html_input' => 'escape',
            'allow_unsafe_links' => false,
            # The lines of a paragraph run on as one, as a browser shows them anyway
            'renderer' => ['soft_break' => ' '],
            'external_link' => [
                # Every link in a spec leaves the CMS: none is internal
                'internal_hosts' => [],
                'open_in_new_window' => true,
                'noopener' => 'all',
                'noreferrer' => 'all',
            ],
        ]);
        $environment->addExtension(new CommonMarkCoreExtension());
        $environment->addExtension(new AutolinkExtension());
        $environment->addExtension(new ExternalLinkExtension());

        $document = (new MarkdownParser($environment))->parse($markdown);
        $renderer = new HtmlRenderer($environment);

        $title = '';
        $first = $document->firstChild();
        if ($first instanceof Heading && $first->getLevel() === 1) {
            # Rendered (so escaped like the body) and stripped of the renderer's own inline tags: plain text
            $title = trim(strip_tags((string) $renderer->renderNodes($first->children())));
            $first->detach();
        }
        foreach ($document->iterator() as $node) {
            if ($node instanceof Heading) {
                $node->setLevel(min(6, $node->getLevel() + 1));
            }
        }

        return ['title' => $title, 'html' => trim((string) $renderer->renderDocument($document))];
    }
}
