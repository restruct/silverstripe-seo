<?php

namespace Restruct\Silverstripe\SEO\Text;

use Normalizer;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Injector\Injectable;

/**
 * An approximation of how wide a title is in a search result, in pixels, from a table of character widths.
 *
 * Search engines truncate titles to the width of the result, not to a number of characters, so "W" counts for
 * more than "i". The table holds Arial's advance widths (in 1/1000 em), which is close to what Google uses for
 * result titles on desktop; the result is an estimate, which is why the title-width check is advisory.
 *
 * The same table goes to the browser (see the snippet preview), so the live counter and the check agree.
 */
class TitleWidth
{
    use Configurable;
    use Injectable;

    /**
     * Font size of a result title, in pixels.
     */
    private static int $font_size = 20;

    /**
     * Width of a character missing from the table, in 1/1000 em (an average lowercase letter).
     */
    private static int $default_width = 556;

    /**
     * Arial advance widths, 1/1000 em. Accented letters are measured as their base letter.
     */
    private static array $char_widths = [
        ' ' => 278, '!' => 278, '"' => 355, '#' => 556, '$' => 556, '%' => 889, '&' => 667, "'" => 191,
        '(' => 333, ')' => 333, '*' => 389, '+' => 584, ',' => 278, '-' => 333, '.' => 278, '/' => 278,
        '0' => 556, '1' => 556, '2' => 556, '3' => 556, '4' => 556, '5' => 556, '6' => 556, '7' => 556,
        '8' => 556, '9' => 556, ':' => 278, ';' => 278, '<' => 584, '=' => 584, '>' => 584, '?' => 556,
        '@' => 1015, 'A' => 667, 'B' => 667, 'C' => 722, 'D' => 722, 'E' => 667, 'F' => 611, 'G' => 778,
        'H' => 722, 'I' => 278, 'J' => 500, 'K' => 667, 'L' => 556, 'M' => 833, 'N' => 722, 'O' => 778,
        'P' => 667, 'Q' => 778, 'R' => 722, 'S' => 667, 'T' => 611, 'U' => 722, 'V' => 667, 'W' => 944,
        'X' => 667, 'Y' => 667, 'Z' => 611, '[' => 278, '\\' => 278, ']' => 278, '^' => 469, '_' => 556,
        '`' => 333, 'a' => 556, 'b' => 556, 'c' => 500, 'd' => 556, 'e' => 556, 'f' => 278, 'g' => 556,
        'h' => 556, 'i' => 222, 'j' => 222, 'k' => 500, 'l' => 222, 'm' => 833, 'n' => 556, 'o' => 556,
        'p' => 556, 'q' => 556, 'r' => 333, 's' => 500, 't' => 278, 'u' => 556, 'v' => 500, 'w' => 722,
        'x' => 500, 'y' => 500, 'z' => 500, '{' => 334, '|' => 260, '}' => 334, '~' => 584,
    ];

    /**
     * The estimated width of the text, in pixels, rounded to one decimal.
     */
    public function pixels(string $text): float
    {
        $widths = $this->widths();
        $default = (int) static::config()->get('default_width');
        $units = 0;
        foreach (mb_str_split($this->baseLetters($text)) as $char) {
            $units += $widths[$char] ?? $default;
        }
        return round($units * (int) static::config()->get('font_size') / 1000, 1);
    }

    /**
     * @return array<string, int>
     */
    public function widths(): array
    {
        return array_map('intval', (array) static::config()->get('char_widths'));
    }

    /**
     * The table and its parameters, for the browser.
     *
     * @return array{widths: array<string, int>, defaultWidth: int, fontSize: int}
     */
    public function clientConfig(): array
    {
        return [
            'widths' => $this->widths(),
            'defaultWidth' => (int) static::config()->get('default_width'),
            'fontSize' => (int) static::config()->get('font_size'),
        ];
    }

    /**
     * "é" as "e": decomposed, with the combining marks dropped. The browser does the same with normalize('NFD').
     */
    protected function baseLetters(string $text): string
    {
        if (class_exists(Normalizer::class)) {
            $text = (string) Normalizer::normalize($text, Normalizer::FORM_D);
        }
        return (string) preg_replace('/\p{Mn}+/u', '', $text);
    }
}
