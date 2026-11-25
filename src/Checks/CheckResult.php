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
     * @param string[] $details
     */
    private function __construct(
        private readonly string $status,
        private readonly string $message = '',
        private readonly array $details = [],
        private readonly string $howToFix = ''
    ) {
    }

    public static function pass(): self
    {
        return new self(self::PASS);
    }

    /**
     * @param string[] $details Plain text, one item each (an image source, a link and its text); escaped on output
     * @param string $howToFix Specific advice; empty to use the check's general advice
     */
    public static function fail(string $message, array $details = [], string $howToFix = ''): self
    {
        return new self(self::FAIL, $message, array_values(array_map('strval', $details)), $howToFix);
    }

    public static function skipped(string $reason): self
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

    public function getMessage(): string
    {
        return $this->message;
    }

    /**
     * @return string[]
     */
    public function getDetails(): array
    {
        return $this->details;
    }

    public function getHowToFix(): string
    {
        return $this->howToFix;
    }
}
