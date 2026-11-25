<?php

namespace Restruct\Silverstripe\SEO\Text;

use DOMElement;
use DOMNode;
use DOMText;
use SilverStripe\View\Parsers\HTMLValue;

/**
 * Field HTML reduced to the simple markup structured data may carry (Google shows a JobPosting description with
 * paragraphs, lists and emphasis): `p`, `br`, `ul`, `ol`, `li`, `strong`, `em`, `h2` to `h4`, without any
 * attributes. Other elements are unwrapped (their text stays), and `script`, `style` and similar are dropped with
 * their content. Registered shortcodes are removed.
 */
class SchemaHtml
{
    protected const ALLOWED = ['p', 'br', 'ul', 'ol', 'li', 'strong', 'em', 'h2', 'h3', 'h4'];

    /**
     * Elements whose content is not text either.
     */
    protected const DROPPED = ['script', 'style', 'template', 'noscript', 'iframe', 'object', 'embed', 'svg', 'math', 'head', 'title'];

    /**
     * The same meaning under an allowed name.
     */
    protected const RENAMED = ['b' => 'strong', 'i' => 'em'];

    public static function sanitise(string $html): string
    {
        $html = DescriptionSummary::stripShortcodes($html);
        if (trim(strip_tags($html)) === '') {
            return '';
        }
        $body = HTMLValue::create($html)->getBody();
        return $body ? trim(static::walk($body)) : '';
    }

    protected static function walk(DOMNode $node): string
    {
        $out = '';
        foreach ($node->childNodes as $child) {
            if ($child instanceof DOMText) {
                # The DOM decoded entities once; encode once again for HTML
                $out .= htmlspecialchars($child->textContent, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8');
                continue;
            }
            if (!$child instanceof DOMElement) {
                # Comments and processing instructions
                continue;
            }
            $tag = strtolower($child->tagName);
            if (in_array($tag, static::DROPPED, true)) {
                continue;
            }
            $tag = static::RENAMED[$tag] ?? $tag;
            if ($tag === 'br') {
                $out .= '<br>';
                continue;
            }
            $inner = static::walk($child);
            if (!in_array($tag, static::ALLOWED, true)) {
                $out .= $inner;
            } elseif (trim($inner) !== '') {
                # An empty paragraph or list item carries nothing
                $out .= '<' . $tag . '>' . $inner . '</' . $tag . '>';
            }
        }
        return $out;
    }
}
