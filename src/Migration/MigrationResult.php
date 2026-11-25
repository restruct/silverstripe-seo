<?php

namespace Restruct\Silverstripe\SEO\Migration;

/**
 * One line of the migration report: what happened to one old column (or value) on one stage.
 */
class MigrationResult
{
    /** Copied to a column of this module */
    public const COPY = 'copy';

    /** Already this module's own column */
    public const SAME_COLUMN = 'same';

    /** Has no use any more */
    public const DROPPED = 'dropped';

    /** Has no equivalent here; the hint says what to do instead */
    public const REPORTED = 'reported';

    /** The old column or table is not in the database */
    public const MISSING = 'missing';

    /** Rows with a value in the old column */
    public int $read = 0;

    /** Rows written (or that would be written, on a dry run) to an empty target */
    public int $copied = 0;

    /** Rows whose target already holds the same value */
    public int $same = 0;

    /** Rows whose target holds a different value: kept, unless the run overwrites */
    public int $conflicts = 0;

    /** Rows whose old value cannot be used (a missing file, a malformed URL, an unknown link type) */
    public int $invalid = 0;

    /**
     * @param string|null $stage 'draft', 'live', or null for a table that is not versioned
     */
    public function __construct(
        public readonly string $label,
        public readonly string $status,
        public readonly ?string $stage = null,
        public string $hint = ''
    ) {
    }

    public function format(bool $apply, bool $overwrite): string
    {
        $label = $this->label . ($this->stage ? " [{$this->stage}]" : '') . ':';
        $hint = $this->hint !== '' ? ': ' . $this->hint : '';
        switch ($this->status) {
            case self::SAME_COLUMN:
                return sprintf('%s same column, nothing to copy (%d values in use)', $label, $this->read);
            case self::DROPPED:
                return sprintf('%s dropped, %d values left in place%s', $label, $this->read, $hint);
            case self::REPORTED:
                return sprintf('%s no equivalent, %d values%s', $label, $this->read, $hint);
            case self::MISSING:
                return sprintf('%s not found, skipped%s', $label, $hint);
        }
        if ($overwrite) {
            $kept = $apply ? 'overwritten' : 'would be overwritten';
        } else {
            $kept = 'kept';
        }
        return sprintf(
            '%s read %d, %s %d, already same %d, conflicts %d (%s), invalid %d%s',
            $label,
            $this->read,
            $apply ? 'copied' : 'would copy',
            $this->copied,
            $this->same,
            $this->conflicts,
            $kept,
            $this->invalid,
            $hint
        );
    }
}
