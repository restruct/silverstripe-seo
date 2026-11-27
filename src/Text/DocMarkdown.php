<?php

namespace Restruct\Silverstripe\SEO\Text;

use SilverStripe\Core\Injector\Injectable;

/**
 * Renders a check spec (docs/checks/<id>.md) for the panel's "About this check" dialog.
 *
 * A deliberately small Markdown subset, the one the specs are written in: headings, paragraphs, fenced code
 * blocks, bullet and numbered lists, inline code, bold, `[text](url)` links and bare URLs. No Markdown library is
 * installed with the framework, and the specs need no more than this.
 *
 * Nothing of the source passes through as HTML: every piece of text is escaped on its own and only the tags this
 * class writes appear in the output. A link is kept only for an http(s) URL; any other link keeps its text and
 * loses the link. Links open in a new tab, as the dialog sits inside the edit form.
 */
class DocMarkdown
{
    use Injectable;

    /**
     * The first `# ` heading as plain text (escaped), and the rest as HTML. Headings move down two levels
     * (`##` becomes `<h3>`): the dialog's own title sits above them.
     *
     * @return array{title: string, html: string}
     */
    public function render(string $markdown): array
    {
        $lines = preg_split('/\R/u', $markdown) ?: [];
        $title = '';
        $html = [];
        # The block being collected: 'p' (paragraph lines), 'ul' / 'ol' (items), 'code' (raw lines)
        $block = null;
        $buffer = [];

        $flush = function () use (&$block, &$buffer, &$html) {
            if ($block === 'p' && $buffer) {
                $html[] = '<p>' . $this->inline(implode(' ', $buffer)) . '</p>';
            } elseif (($block === 'ul' || $block === 'ol') && $buffer) {
                $items = array_map(fn ($item) => '<li>' . $this->inline($item) . '</li>', $buffer);
                $html[] = "<$block>" . implode('', $items) . "</$block>";
            } elseif ($block === 'code') {
                $html[] = '<pre><code>' . $this->escape(implode("\n", $buffer)) . '</code></pre>';
            }
            $block = null;
            $buffer = [];
        };

        foreach ($lines as $line) {
            if ($block === 'code') {
                # Inside a fence everything is text, up to the closing fence
                if (preg_match('/^\s*```/', $line)) {
                    $flush();
                } else {
                    $buffer[] = $line;
                }
                continue;
            }
            if (preg_match('/^\s*```/', $line)) {
                $flush();
                $block = 'code';
                continue;
            }
            if (trim($line) === '') {
                $flush();
                continue;
            }
            if (preg_match('/^(#{1,6})\s+(.*?)\s*#*\s*$/', $line, $m)) {
                $flush();
                $level = strlen($m[1]);
                if ($level === 1 && $title === '' && !$html) {
                    $title = $this->escape($m[2]);
                } else {
                    $tag = 'h' . min(6, $level + 1);
                    $html[] = "<$tag>" . $this->inline($m[2]) . "</$tag>";
                }
                continue;
            }
            if (preg_match('/^\s*[-*]\s+(.*)$/', $line, $m) || preg_match('/^\s*\d+[.)]\s+(.*)$/', $line, $n)) {
                $type = isset($m[1]) ? 'ul' : 'ol';
                if ($block !== $type) {
                    $flush();
                    $block = $type;
                }
                $buffer[] = $m[1] ?? $n[1];
                continue;
            }
            if ($block === 'ul' || $block === 'ol') {
                # A continuation line of the last item
                $buffer[count($buffer) - 1] .= ' ' . trim($line);
                continue;
            }
            $block = 'p';
            $buffer[] = trim($line);
        }
        $flush();

        return ['title' => $title, 'html' => implode("\n", $html)];
    }

    /**
     * One line of text: code spans, links, bare URLs and bold become tags, everything else is escaped.
     */
    protected function inline(string $text): string
    {
        $pattern = '/`([^`]+)`'
            . '|\[([^\]]+)\]\(([^)\s]+)\)'
            . '|(https?:\/\/[^\s<>"\'`()\[\]]+)'
            . '|\*\*(.+?)\*\*/u';
        $out = '';
        $offset = 0;
        preg_match_all($pattern, $text, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        foreach ($matches as $match) {
            [$whole, $at] = $match[0];
            $out .= $this->escape(substr($text, $offset, $at - $offset));
            $offset = $at + strlen($whole);
            if (isset($match[1]) && $match[1][1] >= 0) {
                $out .= '<code>' . $this->escape($match[1][0]) . '</code>';
            } elseif (isset($match[2]) && $match[2][1] >= 0) {
                $out .= $this->link($match[3][0], $this->escape($match[2][0]));
            } elseif (isset($match[4]) && $match[4][1] >= 0) {
                # Punctuation at the end belongs to the sentence, not to the URL
                $url = rtrim($match[4][0], '.,;:!?');
                $out .= $this->link($url, $this->escape($url));
                $offset -= strlen($match[4][0]) - strlen($url);
            } elseif (isset($match[5]) && $match[5][1] >= 0) {
                $out .= '<strong>' . $this->escape($match[5][0]) . '</strong>';
            }
        }
        return $out . $this->escape(substr($text, $offset));
    }

    /**
     * A link for an http(s) URL; for anything else (javascript:, data:, a relative path) only its text.
     */
    protected function link(string $url, string $escapedText): string
    {
        if (!preg_match('#^https?://#i', $url)) {
            return $escapedText;
        }
        return '<a href="' . $this->escape($url) . '" target="_blank" rel="noopener noreferrer">' . $escapedText . '</a>';
    }

    protected function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }
}
