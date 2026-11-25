<?php

namespace Restruct\Silverstripe\SEO\Migration;

use Restruct\Silverstripe\SEO\Extensions\SeoSiteTreeExtension;
use SilverStripe\ORM\DB;

/**
 * One run of a migration: the helpers a {@see SeoMigrationMapper} uses to read old columns and write new ones,
 * the rules every write follows, and the report.
 *
 * The rules:
 * - nothing is written on a dry run (the default);
 * - a target that already holds a value is never changed, unless the run overwrites; a target holding the same
 *   value counts as "already same", so a second run copies nothing (idempotent);
 * - versioned tables are written per stage, with raw SQL: the draft table gets the old draft value and the _Live
 *   table the old live value. Nothing is published and no version is written, so a page with unpublished
 *   changes keeps them unpublished, and a published page does not show up as modified. (Writing through the ORM
 *   and then publishing would publish whatever else was waiting in draft, and writing only the draft would mark
 *   every migrated page as modified.) The page history (_Versions) is not changed: rolling a page back to a
 *   version from before the migration gives the empty column of that version.
 * - old columns are read with raw SQL, from the table the old module put them on, or from `_obsolete_<table>`
 *   when db:build has since renamed that table (it does so once no class gives it a field any more). They are
 *   never changed or dropped.
 */
class MigrationRun
{
    public const STAGE_DRAFT = 'draft';

    public const STAGE_LIVE = 'live';

    /**
     * Returned by a transform for a row that has a value, but none for this target (e.g. a robots string
     * without "noindex", for the noindex flag): not counted.
     */
    public const NOTHING = false;

    /**
     * Tables whose columns are page fields: their labels in the report carry no table name.
     */
    protected const PAGE_TABLES = ['SiteTree', 'Page'];

    /**
     * @var array<array{0: string, 1: MigrationResult|string}> The report, in order: ['result', MigrationResult],
     *      ['info', text], ['note', text] or ['warning', text].
     */
    private array $entries = [];

    /**
     * @var array<string, array<string, true>|false> Column names per table (lowercased), false for no table.
     */
    private array $columns = [];

    /**
     * @var array<int, bool>
     */
    private array $files = [];

    /**
     * @var array<string, array{0: string, 1: int}|null> Per "table.column": [chars|bytes, limit], null for none.
     */
    private array $limits = [];

    public function __construct(
        private readonly bool $apply = false,
        private readonly bool $overwrite = false
    ) {
    }

    public function isApply(): bool
    {
        return $this->apply;
    }

    public function isOverwrite(): bool
    {
        return $this->overwrite;
    }

    # --- Schema --------------------------------------------------------------------------------------------------

    public function hasTable(string $table): bool
    {
        return $this->columnsOf($table) !== false;
    }

    public function hasColumn(string $table, string $column): bool
    {
        $columns = $this->columnsOf($table);
        return $columns !== false && isset($columns[strtolower($column)]);
    }

    /**
     * Where an old column lives: the first of the candidate tables that has it, or that table renamed to
     * `_obsolete_<table>` by db:build.
     *
     * @param string[] $candidates base table names, e.g. ['Page', 'SiteTree']
     * @return array{draft: string, live: ?string}|null the table, and its _Live table when that has the column too
     */
    public function locateColumn(array $candidates, string $column): ?array
    {
        foreach ($candidates as $base) {
            foreach (['', '_obsolete_'] as $prefix) {
                $table = $prefix . $base;
                if ($this->hasColumn($table, $column)) {
                    $live = $prefix . $base . '_Live';
                    return ['draft' => $table, 'live' => $this->hasColumn($live, $column) ? $live : null];
                }
            }
        }
        return null;
    }

    /**
     * Whether a File row (an image too) with this ID exists, so a copied has_one does not point at nothing.
     */
    public function fileExists(int $id): bool
    {
        if ($id <= 0) {
            return false;
        }
        return $this->files[$id] ??= (bool) DB::prepared_query(
            'SELECT COUNT(*) FROM "File" WHERE "ID" = ?',
            [$id]
        )->value();
    }

    /**
     * Rows of a table, or an empty list when the table does not exist.
     *
     * @return array<array<string, mixed>>
     */
    public function rows(string $table, string $where = '', array $params = []): array
    {
        if (!$this->hasTable($table)) {
            return [];
        }
        $sql = sprintf('SELECT * FROM "%s"%s ORDER BY "ID"', $table, $where !== '' ? ' WHERE ' . $where : '');
        return iterator_to_array(DB::prepared_query($sql, $params), false);
    }

    # --- Transforms ----------------------------------------------------------------------------------------------

    /**
     * For an image has_one: the ID when the file exists, otherwise invalid.
     */
    public function imageTransform(): callable
    {
        return fn (mixed $value): ?int => $this->fileExists((int) $value) ? (int) $value : null;
    }

    /**
     * For a canonical URL: kept when this module accepts it (absolute http(s), or root-relative), otherwise
     * invalid.
     */
    public function canonicalTransform(): callable
    {
        return function (mixed $value): ?string {
            $url = trim((string) $value);
            return SeoSiteTreeExtension::isValidCanonicalInput($url) ? $url : null;
        };
    }

    /**
     * A length limit of the mapper's own, stricter than the target column's: a longer value is invalid. (The
     * target column's own limit is checked for every copy already, see {@see fitsColumn()}.)
     */
    public function maxLengthTransform(int $length): callable
    {
        return fn (mixed $value): ?string => mb_strlen((string) $value) <= $length ? (string) $value : null;
    }

    # --- Copying and reporting -----------------------------------------------------------------------------------

    /**
     * Copy an old column into one of this module's columns, per stage.
     *
     * With several source columns, the first one holding a value is used (e.g. a Facebook description, else a
     * Twitter one). When the old column turns out to be this module's own column, it is reported as such.
     *
     * @param string[] $sourceTables candidate base tables of the old column, e.g. ['Page', 'SiteTree']
     * @param string|string[] $sourceColumns
     * @param callable|null $transform fn(mixed $value, ?string $stage, int $id): mixed, returning the value to
     *        write, null when the old value is unusable (counted as invalid), or {@see NOTHING}
     * @param bool $numeric the target is an ID or a flag, so 0 counts as empty
     */
    public function copyColumn(
        array $sourceTables,
        string|array $sourceColumns,
        string $targetTable,
        string $targetColumn,
        ?callable $transform = null,
        bool $numeric = false
    ): void {
        $sourceColumns = (array) $sourceColumns;
        $prefix = $this->labelPrefix($targetTable);
        $label = $prefix . implode(', ', $sourceColumns) . ' -> ' . $targetColumn;

        $located = [];
        foreach ($sourceColumns as $column) {
            if ($found = $this->locateColumn($sourceTables, $column)) {
                $located[$column] = $found;
            }
        }
        if (!$located) {
            $this->add(new MigrationResult($label, MigrationResult::MISSING));
            return;
        }
        if (!$this->hasColumn($targetTable, $targetColumn)) {
            $this->add(new MigrationResult($label, MigrationResult::MISSING, null, 'run db:build first'));
            return;
        }

        # The old column is this module's own (same name on the same table): it is in use as it is
        if ($sourceColumns === [$targetColumn] && $located[$targetColumn]['draft'] === $targetTable) {
            $result = new MigrationResult($prefix . $targetColumn, MigrationResult::SAME_COLUMN);
            $result->read = $this->countValues($targetTable, $targetColumn);
            $this->add($result);
            return;
        }

        foreach ($this->stagesOf($targetTable) as [$stage, $table]) {
            $joins = [];
            $selects = [];
            $index = 0;
            foreach ($located as $column => $tables) {
                $source = $stage === self::STAGE_LIVE ? $tables['live'] : $tables['draft'];
                if ($source === null) {
                    continue;
                }
                $joins[] = sprintf('LEFT JOIN "%1$s" "s%2$d" ON "s%2$d"."ID" = "t"."ID"', $source, $index);
                $selects[] = sprintf('"s%d"."%s" AS "v%d"', $index, $column, $index);
                $index++;
            }
            $result = new MigrationResult($label, MigrationResult::COPY, $stage);
            $this->add($result);
            if (!$selects) {
                $result->hint = 'the old column has no _Live table, so the live stage is left as it is';
                continue;
            }

            $rows = DB::query(sprintf(
                'SELECT "t"."ID" AS "ID", "t"."%s" AS "current", %s FROM "%s" "t" %s ORDER BY "t"."ID"',
                $targetColumn,
                implode(', ', $selects),
                $table,
                implode(' ', $joins)
            ));
            foreach ($rows as $row) {
                $value = null;
                for ($i = 0; $i < $index; $i++) {
                    # The target's type does not tell the source's: a robots string feeds a flag
                    if (!$this->isEmptySource($row["v{$i}"])) {
                        $value = $row["v{$i}"];
                        break;
                    }
                }
                if ($value === null) {
                    continue;
                }
                if ($transform) {
                    $value = $transform($value, $stage, (int) $row['ID']);
                    if ($value === self::NOTHING) {
                        continue;
                    }
                }
                $result->read++;
                if ($value === null) {
                    $result->invalid++;
                    continue;
                }
                $this->offer($result, $table, $targetColumn, (int) $row['ID'], $value, $row['current'], $numeric);
            }
        }
    }

    /**
     * Copy one value into one row, e.g. a site-wide value found in an old module's own table, to SiteConfig.
     */
    public function copyValue(
        string $label,
        string $table,
        string $column,
        int $id,
        mixed $value,
        bool $numeric = false
    ): MigrationResult {
        $result = new MigrationResult($label, MigrationResult::COPY);
        $this->add($result);
        if (!$this->hasColumn($table, $column)) {
            $result->hint = "{$table}.{$column} not found, run db:build first";
            return $result;
        }
        $result->read = 1;
        if ($value === null) {
            $result->invalid = 1;
            return $result;
        }
        $current = DB::prepared_query(sprintf('SELECT "%s" FROM "%s" WHERE "ID" = ?', $column, $table), [$id])->value();
        $this->offer($result, $table, $column, $id, $value, $current, $numeric);
        return $result;
    }

    /**
     * Report an old column that is not copied, with the number of rows holding a value, per stage.
     *
     * @param string $status {@see MigrationResult::DROPPED} or {@see MigrationResult::REPORTED}
     */
    public function reportColumn(array $sourceTables, string $column, string $status, string $hint = ''): void
    {
        $label = $this->labelPrefix($sourceTables[0] ?? '') . $column;
        $located = $this->locateColumn($sourceTables, $column);
        if (!$located) {
            $this->add(new MigrationResult($label, MigrationResult::MISSING));
            return;
        }
        $versioned = $this->hasTable($this->baseOf($located['draft']) . '_Live');
        foreach ([self::STAGE_DRAFT => $located['draft'], self::STAGE_LIVE => $located['live']] as $stage => $table) {
            if ($table === null) {
                continue;
            }
            $result = new MigrationResult($label, $status, $versioned ? $stage : null, $hint);
            $result->read = $this->countValues($table, $column);
            $this->add($result);
        }
    }

    /**
     * Report a count found some other way, e.g. rows of an old module's own table.
     */
    public function reportCount(string $label, string $status, int $count, string $hint = ''): void
    {
        $result = new MigrationResult($label, $status, null, $hint);
        $result->read = $count;
        $this->add($result);
    }

    /**
     * A plain line of the report, e.g. a summary of an old module's own tables.
     */
    public function info(string $text): void
    {
        $this->entries[] = ['info', $text];
    }

    public function note(string $text): void
    {
        $this->entries[] = ['note', $text];
    }

    public function warn(string $text): void
    {
        $this->entries[] = ['warning', $text];
    }

    /**
     * @return array<array{0: string, 1: MigrationResult|string}>
     */
    public function getEntries(): array
    {
        return $this->entries;
    }

    /**
     * @return MigrationResult[]
     */
    public function getResults(): array
    {
        return array_map(
            fn (array $entry) => $entry[1],
            array_values(array_filter($this->entries, fn (array $entry) => $entry[0] === 'result'))
        );
    }

    # --- Internals -----------------------------------------------------------------------------------------------

    /**
     * Apply the write rules to one row.
     */
    protected function offer(
        MigrationResult $result,
        string $table,
        string $column,
        int $id,
        mixed $value,
        mixed $current,
        bool $numeric
    ): void {
        if (!$numeric && !$this->fitsColumn($table, $column, $value)) {
            # Silverstripe's ANSI sql_mode is not strict: MySQL would cut the value off without a word
            $result->invalid++;
            return;
        }
        if ($this->isEmpty($current, $numeric)) {
            $result->copied++;
            $this->write($table, $column, $id, $value);
            return;
        }
        $same = $numeric ? (int) $current === (int) $value : (string) $current === (string) $value;
        if ($same) {
            $result->same++;
            return;
        }
        $result->conflicts++;
        if ($this->overwrite) {
            $this->write($table, $column, $id, $value);
        }
    }

    /**
     * Whether a text value fits the column: VARCHAR and CHAR limits count characters, TEXT types bytes.
     */
    protected function fitsColumn(string $table, string $column, mixed $value): bool
    {
        $key = $table . '.' . $column;
        if (!array_key_exists($key, $this->limits)) {
            $info = DB::prepared_query(
                'SELECT "DATA_TYPE", "CHARACTER_MAXIMUM_LENGTH", "CHARACTER_OCTET_LENGTH" FROM information_schema."COLUMNS"'
                . ' WHERE "TABLE_SCHEMA" = DATABASE() AND "TABLE_NAME" = ? AND "COLUMN_NAME" = ?',
                [$table, $column]
            )->record();
            $type = strtolower((string) ($info['DATA_TYPE'] ?? ''));
            if (in_array($type, ['varchar', 'char'], true)) {
                $this->limits[$key] = ['chars', (int) $info['CHARACTER_MAXIMUM_LENGTH']];
            } elseif (str_ends_with($type, 'text')) {
                $this->limits[$key] = ['bytes', (int) $info['CHARACTER_OCTET_LENGTH']];
            } else {
                $this->limits[$key] = null;
            }
        }
        $limit = $this->limits[$key];
        if ($limit === null || $limit[1] <= 0) {
            return true;
        }
        $length = $limit[0] === 'chars' ? mb_strlen((string) $value) : strlen((string) $value);
        return $length <= $limit[1];
    }

    protected function write(string $table, string $column, int $id, mixed $value): void
    {
        if (!$this->apply) {
            return;
        }
        DB::prepared_query(sprintf('UPDATE "%s" SET "%s" = ? WHERE "ID" = ?', $table, $column), [$value, $id]);
    }

    protected function isEmpty(mixed $value, bool $numeric = false): bool
    {
        if ($value === null || trim((string) $value) === '') {
            return true;
        }
        return $numeric && (int) $value === 0;
    }

    /**
     * An old value that holds nothing: empty, or a zero, so an unset flag or has_one is not read.
     */
    protected function isEmptySource(mixed $value): bool
    {
        return $this->isEmpty($value) || (is_numeric($value) && (float) $value === 0.0);
    }

    /**
     * Rows holding a value (see {@see isEmptySource()}).
     */
    protected function countValues(string $table, string $column): int
    {
        $count = 0;
        foreach (DB::query(sprintf('SELECT "%s" AS "v" FROM "%s"', $column, $table)) as $row) {
            if (!$this->isEmptySource($row['v'])) {
                $count++;
            }
        }
        return $count;
    }

    /**
     * @return array<array{0: ?string, 1: string}> [stage, table] pairs: draft and live for a versioned table
     */
    protected function stagesOf(string $table): array
    {
        if ($this->hasTable($table . '_Live')) {
            return [[self::STAGE_DRAFT, $table], [self::STAGE_LIVE, $table . '_Live']];
        }
        return [[null, $table]];
    }

    protected function baseOf(string $table): string
    {
        return preg_replace('/_Live$/', '', $table);
    }

    protected function labelPrefix(string $table): string
    {
        $base = preg_replace('/^_obsolete_/', '', $this->baseOf($table));
        return in_array($base, self::PAGE_TABLES, true) || $base === '' ? '' : $base . '.';
    }

    protected function add(MigrationResult $result): void
    {
        $this->entries[] = ['result', $result];
    }

    /**
     * @return array<string, true>|false
     */
    private function columnsOf(string $table): array|false
    {
        if (!array_key_exists($table, $this->columns)) {
            $this->columns[$table] = DB::get_schema()->hasTable($table)
                ? array_fill_keys(array_map('strtolower', array_keys(DB::field_list($table))), true)
                : false;
        }
        return $this->columns[$table];
    }
}
