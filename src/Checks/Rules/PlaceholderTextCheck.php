<?php

namespace Restruct\Silverstripe\SEO\Checks\Rules;

use Restruct\Silverstripe\SEO\Checks\AbstractCheck;
use Restruct\Silverstripe\SEO\Checks\CheckResult;
use Restruct\Silverstripe\SEO\Checks\PageAnalysis;

/**
 * Check 18: placeholder text left in the content, the title or the description: "lorem ipsum", "TODO", "xxx".
 */
class PlaceholderTextCheck extends AbstractCheck
{
    private static string $id = 'placeholder-text';

    private static string $group = self::GROUP_CONTENT;

    private static string $severity = self::PROBLEM;

    private static string $priority = self::HIGH;

    private static bool $needs_render = true;

    /**
     * PCRE patterns, keyed so a project can switch one off (set it to an empty string) or add its own.
     */
    private static array $patterns = [
        'lorem' => '/\blorem ipsum\b/iu',
        'dolor' => '/\bdolor sit amet\b/iu',
        'todo' => '/\bTODO\b/u',
        'fixme' => '/\bFIXME\b/u',
        'tbd' => '/\bTBD\b/u',
        'xxx' => '/(?<![\p{L}\p{N}])x{3,}(?![\p{L}\p{N}])/iu',
        'bracketed' => '/\[(?:placeholder|text here|tekst hier|tekst volgt)\]/iu',
        'nl-follows' => '/\btekst volgt\b/iu',
    ];

    public function title(): string
    {
        return _t(__CLASS__ . '.Title', 'No placeholder text');
    }

    public function howToFix(): string
    {
        return _t(
            __CLASS__ . '.HowToFix',
            'Replace the placeholder with the real text, or remove it, before the page goes live.'
        );
    }

    public function run(PageAnalysis $analysis): CheckResult
    {
        $sources = [
            [$this->_t(__CLASS__ . '.InContent', 'content'), $analysis->contentText()],
            [$this->_t(__CLASS__ . '.InTitle', 'title'), (string) $analysis->title()],
            [$this->_t(__CLASS__ . '.InDescription', 'description'), $analysis->description()],
        ];
        $found = [];
        foreach ($sources as [$where, $text]) {
            foreach ((array) static::config()->get('patterns') as $pattern) {
                if ($pattern && preg_match_all((string) $pattern, $text, $matches, PREG_OFFSET_CAPTURE)) {
                    foreach ($matches[0] as [$match, $offset]) {
                        $found[] = $this->_t(__CLASS__ . '.Found', '{where}: "{match}" in "{context}"', [
                            'where' => $where,
                            'match' => $match,
                            'context' => $this->context($text, (int) $offset, $match),
                        ]);
                    }
                }
            }
        }
        if (!$found) {
            return $this->pass();
        }
        return $this->fail(
            $this->_t(__CLASS__ . '.Message', 'The page still contains placeholder text.'),
            static::unique($found)
        );
    }

    /**
     * A few words around the match (the offset is in bytes, as preg reports it).
     */
    protected function context(string $text, int $offset, string $match): string
    {
        $start = max(0, $offset - 30);
        $snippet = substr($text, $start, strlen($match) + 60);
        # Not cut in the middle of a multibyte character
        $snippet = mb_convert_encoding($snippet, 'UTF-8', 'UTF-8');
        return ($start > 0 ? '...' : '') . trim((string) $snippet) . '...';
    }
}
