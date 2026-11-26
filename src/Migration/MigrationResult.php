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

    /** Rows whose value points at an unpublished file: not written to the live stage */
    public int $unpublished = 0;

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
        # The stage in the site's locale; the column names in the label are the database's and stay as they are
        $stage = match ($this->stage) {
            'draft' => _t(__CLASS__ . '.StageDraft', 'draft'),
            'live' => _t(__CLASS__ . '.StageLive', 'live'),
            default => $this->stage,
        };
        $label = $this->label . ($stage ? " [{$stage}]" : '') . ':';
        $hint = $this->hint !== '' ? ': ' . $this->hint : '';
        $values = ['label' => $label, 'read' => $this->read, 'hint' => $hint];
        switch ($this->status) {
            case self::SAME_COLUMN:
                return _t(__CLASS__ . '.Same', '{label} same column, nothing to copy ({read} values in use)', $values);
            case self::DROPPED:
                return _t(__CLASS__ . '.Dropped', '{label} dropped, {read} values left in place{hint}', $values);
            case self::REPORTED:
                return _t(__CLASS__ . '.Reported', '{label} no equivalent, {read} values{hint}', $values);
            case self::MISSING:
                return _t(__CLASS__ . '.Missing', '{label} not found, skipped{hint}', $values);
        }
        if ($overwrite) {
            $kept = $apply
                ? _t(__CLASS__ . '.Overwritten', 'overwritten')
                : _t(__CLASS__ . '.WouldBeOverwritten', 'would be overwritten');
        } else {
            $kept = _t(__CLASS__ . '.Kept', 'kept');
        }
        # Only when it happens: a file of the old page that was never published
        $unpublished = $this->unpublished ? _t(
            __CLASS__ . '.Unpublished',
            ', unpublished {count} (file not published, live left as it is)',
            ['count' => $this->unpublished]
        ) : '';
        return _t(
            __CLASS__ . ($apply ? '.Copy' : '.WouldCopy'),
            $apply
                ? '{label} read {read}, copied {copied}, already same {same}, conflicts {conflicts} ({kept}), invalid {invalid}{unpublished}{hint}'
                : '{label} read {read}, would copy {copied}, already same {same}, conflicts {conflicts} ({kept}), invalid {invalid}{unpublished}{hint}',
            $values + [
                'copied' => $this->copied,
                'same' => $this->same,
                'conflicts' => $this->conflicts,
                'kept' => $kept,
                'invalid' => $this->invalid,
                'unpublished' => $unpublished,
            ]
        );
    }
}
