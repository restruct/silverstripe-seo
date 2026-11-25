<?php

namespace Restruct\Silverstripe\SEO\Checks;

/**
 * The outcome of one check on one page: passed, failed (with a message, details such as the offending image
 * sources or links, and how to fix it) or skipped (it could not run, for example because the page did not render).
 */
final class CheckResult
{
    public const PASS = 'pass';
    public const FAIL = 'fail';
    public const SKIPPED = 'skipped';

    /**
     * Message, details and how-to-fix are plain text or {@see CheckMessage} arrays, translated when shown.
     *
     * @param array<int, string|array> $details
     */
    private function __construct(
        private readonly string $status,
        private readonly string|array $message = '',
        private readonly array $details = [],
        private readonly string|array $howToFix = ''
    ) {
    }

    public static function pass(): self
    {
        return new self(self::PASS);
    }

    /**
     * @param array<int, string|array> $details Plain text or messages, one item each (an image source, a link
     *                                       and its text); escaped on output
     * @param string|array $howToFix Specific advice; empty to use the check's general advice
     */
    public static function fail(string|array $message, array $details = [], string|array $howToFix = ''): self
    {
        $details = array_values(array_map(fn ($d) => is_array($d) ? $d : (string) $d, $details));
        return new self(self::FAIL, $message, $details, $howToFix);
    }

    public static function skipped(string|array $reason): self
    {
        return new self(self::SKIPPED, $reason);
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function isPass(): bool
    {
        return $this->status === self::PASS;
    }

    public function isFail(): bool
    {
        return $this->status === self::FAIL;
    }

    public function getMessage(): string|array
    {
        return $this->message;
    }

    /**
     * @return array<int, string|array>
     */
    public function getDetails(): array
    {
        return $this->details;
    }

    public function getHowToFix(): string|array
    {
        return $this->howToFix;
    }
}
