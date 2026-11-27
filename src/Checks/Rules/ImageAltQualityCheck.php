<?php

namespace Restruct\Silverstripe\SEO\Checks\Rules;

use Restruct\Silverstripe\SEO\Checks\AbstractCheck;
use Restruct\Silverstripe\SEO\Checks\CheckResult;
use Restruct\Silverstripe\SEO\Checks\PageAnalysis;

/**
 * Check 12: alt text that is there but does not describe the image: a file name, "image of ...", very short or
 * very long, the same text on different images, or a list of repeated keywords.
 */
class ImageAltQualityCheck extends AbstractCheck
{
    private static string $id = 'image-alt-quality';

    private static string $group = self::GROUP_IMAGES;

    private static string $severity = self::TIP;

    private static string $priority = self::MEDIUM;

    private static bool $needs_render = true;

    /**
     * Characters.
     */
    private static int $min_length = 5;

    private static int $max_length = 150;

    /**
     * A word (of 4 letters or more) used this often in one alt text counts as stuffing.
     */
    private static int $stuffing_repeats = 3;

    /**
     * Openings that add nothing (screen readers already announce an image), compared case-insensitively.
     */
    private static array $redundant_prefixes = [
        'image of',
        'picture of',
        'photo of',
        'graphic of',
        'afbeelding van',
        'foto van',
        'plaatje van',
    ];


    public function title(): string
    {
        return _t(__CLASS__ . '.Title', 'Alt text describes the image');
    }

    /**
     * What the check looks at and why it matters, for the editor reading the "About this check" dialog.
     */
    public function about(): string
    {
        return _t(
            __CLASS__ . '.About',
            'Checks that the alternative texts of the images really describe them: not a file name such as IMG_1234, not "image of", not the same text on different images, and neither very short nor very long. A good description helps people using a screen reader and search engines alike.'
        );
    }

    public function failTitle(): string
    {
        return _t(__CLASS__ . '.FailTitle', 'Alt text does not describe the image');
    }

    public function howToFix(): string
    {
        return _t(
            __CLASS__ . '.HowToFix',
            'Describe what the image shows, in a short sentence, and say what matters about it on this page. No file names, no "image of", no lists of keywords; two different images need two different descriptions.'
        );
    }

    public function run(PageAnalysis $analysis): CheckResult
    {
        $issues = [];
        $seen = [];
        foreach ($analysis->query('.//img[@alt]') as $img) {
            $alt = trim((string) preg_replace('/\s+/u', ' ', $img->getAttribute('alt')));
            if ($alt === '') {
                # Missing or decorative: check 11's business
                continue;
            }
            $src = static::short((string) $img->getAttribute('src'), 80);
            $reason = $this->problemWith($alt);
            if ($reason === null) {
                $key = mb_strtolower($alt);
                if (isset($seen[$key]) && $seen[$key] !== $src) {
                    $reason = $this->_t(__CLASS__ . '.Duplicate', 'same alt text as {src}', ['src' => $seen[$key]]);
                }
                $seen[$key] ??= $src;
            }
            if ($reason !== null) {
                $issues[] = $this->_t(__CLASS__ . '.Issue', '{src}: "{alt}" ({reason})', [
                    'src' => $src,
                    'alt' => static::short($alt, 60),
                    'reason' => $reason,
                ]);
            }
        }
        if (!$issues) {
            return $this->pass();
        }
        return $this->fail(
            $this->_t(__CLASS__ . '.Message', 'The alt text of {count} image(s) could describe the image better.', ['count' => count($issues)]),
            $issues
        );
    }

    protected function problemWith(string $alt): ?array
    {
        # A file name (IMG_4031.jpg, header-banner.png) or a camera name (DSC01234, IMG_4031)
        if (preg_match('/\.(jpe?g|png|gif|webp|avif|svg|tiff?|bmp|heic)$/i', $alt)
            || preg_match('/^(img|dsc|dscn|dcim|pxl|photo|image|screenshot)[_\-]?\d+/i', $alt)
        ) {
            return $this->_t(__CLASS__ . '.FileName', 'looks like a file name');
        }
        $lower = mb_strtolower($alt);
        foreach ((array) static::config()->get('redundant_prefixes') as $prefix) {
            if ($prefix !== '' && str_starts_with($lower, mb_strtolower((string) $prefix))) {
                return $this->_t(__CLASS__ . '.Prefix', 'starts with "{prefix}"', ['prefix' => mb_substr($alt, 0, mb_strlen((string) $prefix))]);
            }
        }
        $length = mb_strlen($alt);
        if ($length < (int) static::config()->get('min_length')) {
            return $this->_t(__CLASS__ . '.Short', 'very short');
        }
        if ($length > (int) static::config()->get('max_length')) {
            return $this->_t(__CLASS__ . '.Long', 'longer than {max} characters', ['max' => (int) static::config()->get('max_length')]);
        }
        $words = preg_split('/[^\p{L}\p{N}]+/u', $lower, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $counts = array_count_values(array_filter($words, fn ($w) => mb_strlen($w) >= 4));
        if ($counts && max($counts) >= (int) static::config()->get('stuffing_repeats')) {
            return $this->_t(__CLASS__ . '.Stuffing', 'repeats "{word}"', ['word' => array_search(max($counts), $counts, true)]);
        }
        return null;
    }
}
