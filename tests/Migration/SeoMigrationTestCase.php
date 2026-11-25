<?php

namespace Restruct\Silverstripe\SEO\Tests\Migration;

use Restruct\Silverstripe\SEO\Tasks\MigrateFromSeoModuleTask;
use Restruct\Silverstripe\SEO\Tests\SeoTestImages;
use SilverStripe\Assets\Dev\TestAssetStore;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\ORM\DB;
use SilverStripe\PolyExecution\PolyOutput;
use SilverStripe\SiteConfig\SiteConfig;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Shared set-up for the migration tests. The old SEO modules are not installed, so their columns and tables are
 * created here with raw SQL, exactly as they are left behind in a site's database, and dropped again afterwards.
 */
abstract class SeoMigrationTestCase extends SapphireTest
{
    use SeoTestImages;

    protected $usesDatabase = true;

    # ALTER TABLE and CREATE TABLE commit implicitly in MySQL, so a test transaction could not roll them back;
    # the columns and tables are dropped in tearDown instead
    protected $usesTransactions = false;

    /**
     * @var array<array{0: string, 1: string}> Columns added to existing tables, as [table, column].
     */
    private array $addedColumns = [];

    /**
     * @var string[] Tables created by the test.
     */
    private array $createdTables = [];

    protected function setUp(): void
    {
        parent::setUp();
        TestAssetStore::activate('SeoMigrationTest');
        # The SiteConfig row the migration writes site-wide values to
        SiteConfig::current_site_config();
    }

    protected function tearDown(): void
    {
        foreach (array_reverse($this->addedColumns) as [$table, $column]) {
            DB::query(sprintf('ALTER TABLE "%s" DROP COLUMN "%s"', $table, $column));
        }
        foreach (array_reverse($this->createdTables) as $table) {
            DB::query(sprintf('DROP TABLE IF EXISTS "%s"', $table));
        }
        $this->addedColumns = [];
        $this->createdTables = [];
        TestAssetStore::reset();
        parent::tearDown();
    }

    /**
     * Add an old module's column to a table, creating the table (with just an ID) when it does not exist.
     */
    protected function addOldColumn(string $table, string $column, string $spec): void
    {
        if (!DB::get_schema()->hasTable($table)) {
            DB::query(sprintf('CREATE TABLE "%s" ("ID" int NOT NULL PRIMARY KEY)', $table));
            $this->createdTables[] = $table;
        }
        if (array_key_exists($column, DB::field_list($table))) {
            return;
        }
        DB::query(sprintf('ALTER TABLE "%s" ADD "%s" %s', $table, $column, $spec));
        if (!in_array($table, $this->createdTables, true)) {
            $this->addedColumns[] = [$table, $column];
        }
    }

    /**
     * The same column on a table and its _Live table.
     */
    protected function addOldStagedColumn(string $table, string $column, string $spec): void
    {
        $this->addOldColumn($table, $column, $spec);
        $this->addOldColumn($table . '_Live', $column, $spec);
    }

    /**
     * Create a whole old table, e.g. a DataObject table of the old module.
     *
     * @param array<string, string> $columns column name => SQL spec (ID is added)
     */
    protected function createOldTable(string $table, array $columns): void
    {
        $parts = ['"ID" int NOT NULL PRIMARY KEY'];
        foreach ($columns as $column => $spec) {
            $parts[] = sprintf('"%s" %s', $column, $spec);
        }
        DB::query(sprintf('DROP TABLE IF EXISTS "%s"', $table));
        DB::query(sprintf('CREATE TABLE "%s" (%s)', $table, implode(', ', $parts)));
        $this->createdTables[] = $table;
    }

    /**
     * Set raw values on a row, inserting the row (by ID) when the table has none yet.
     */
    protected function setRaw(string $table, int $id, array $values): void
    {
        DB::prepared_query(sprintf('INSERT IGNORE INTO "%s" ("ID") VALUES (?)', $table), [$id]);
        foreach ($values as $column => $value) {
            DB::prepared_query(sprintf('UPDATE "%s" SET "%s" = ? WHERE "ID" = ?', $table, $column), [$value, $id]);
        }
    }

    /**
     * Insert a raw row into a join or DataObject table that has no ID-keyed upsert need.
     */
    protected function insertRaw(string $table, array $values): void
    {
        $columns = implode(', ', array_map(fn (string $column) => '"' . $column . '"', array_keys($values)));
        $marks = implode(', ', array_fill(0, count($values), '?'));
        DB::prepared_query(sprintf('INSERT INTO "%s" (%s) VALUES (%s)', $table, $columns, $marks), array_values($values));
    }

    protected function raw(string $table, string $column, int $id): mixed
    {
        return DB::prepared_query(
            sprintf('SELECT "%s" FROM "%s" WHERE "ID" = ?', $column, $table),
            [$id]
        )->value();
    }

    /**
     * A page written and published, so it has a row on both stages.
     */
    protected function publishedPage(string $title, array $data = []): SiteTree
    {
        $page = SiteTree::create(array_merge(['Title' => $title], $data));
        $page->write();
        $page->publishSingle();
        return $page;
    }

    protected function siteConfigID(): int
    {
        return (int) SiteConfig::current_site_config()->ID;
    }

    /**
     * Run the task the way sake does and return its plain-text output.
     *
     * @param array<string, mixed> $options e.g. ['--apply' => true]
     */
    protected function runMigration(?string $source, array $options = [], int $expectedCode = Command::SUCCESS): string
    {
        $task = MigrateFromSeoModuleTask::create();
        $args = $options;
        if ($source !== null) {
            $args['--source'] = $source;
        }
        $input = new ArrayInput($args, new InputDefinition($task->getOptions()));
        $buffer = new BufferedOutput();
        $output = new PolyOutput(PolyOutput::FORMAT_ANSI, wrappedOutput: $buffer);
        $code = $task->run($input, $output);
        $text = $buffer->fetch();
        $this->assertSame($expectedCode, $code, $text);
        return $text;
    }

    /**
     * Assert the report has a line that starts with the given label and contains every fragment.
     */
    protected function assertReportLine(string $output, string $start, array $fragments = []): void
    {
        foreach (preg_split('/\R/', $output) as $line) {
            $line = trim($line);
            if (!str_starts_with($line, $start)) {
                continue;
            }
            foreach ($fragments as $fragment) {
                if (!str_contains($line, $fragment)) {
                    continue 2;
                }
            }
            $this->addToAssertionCount(1);
            return;
        }
        $this->fail(sprintf(
            "No report line starting with \"%s\" containing \"%s\" in:\n%s",
            $start,
            implode('", "', $fragments),
            $output
        ));
    }
}
