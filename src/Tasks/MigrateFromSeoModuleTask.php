<?php

namespace Restruct\Silverstripe\SEO\Tasks;

use Restruct\Silverstripe\SEO\Migration\Mappers\HubertusantonMapper;
use Restruct\Silverstripe\SEO\Migration\Mappers\JellygniteMapper;
use Restruct\Silverstripe\SEO\Migration\Mappers\LiveSeoMapper;
use Restruct\Silverstripe\SEO\Migration\Mappers\PlasticStudioMapper;
use Restruct\Silverstripe\SEO\Migration\Mappers\SimpleSeoMapper;
use Restruct\Silverstripe\SEO\Migration\MigrationResult;
use Restruct\Silverstripe\SEO\Migration\MigrationRun;
use Restruct\Silverstripe\SEO\Migration\SeoMigrationMapper;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\BuildTask;
use SilverStripe\ORM\DB;
use SilverStripe\PolyExecution\PolyOutput;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Throwable;

/**
 * Copies another SEO module's data into this module's columns: `sake tasks:seo-migrate --source=<name>`.
 *
 * A dry run by default; `--apply` writes, inside one transaction. A target that already holds a value is kept
 * unless `--overwrite` is given. Old columns are read with raw SQL and never changed, so the run can be repeated
 * and the old module removed afterwards. The rules are on {@see MigrationRun}; one {@see SeoMigrationMapper} per
 * source module knows its columns.
 */
class MigrateFromSeoModuleTask extends BuildTask
{
    protected static string $commandName = 'seo-migrate';

    protected string $title = 'Migrate SEO data from another SEO module';

    protected static string $description = 'Copy page and site SEO data from another SEO module into this one '
        . '(dry run unless --apply)';

    /**
     * Source name (the --source value) => mapper class. A project can add its own mapper here.
     *
     * @config
     */
    private static array $mappers = [
        'liveseo' => LiveSeoMapper::class,
        'hubertusanton' => HubertusantonMapper::class,
        'plasticstudio' => PlasticStudioMapper::class,
        'simpleseo' => SimpleSeoMapper::class,
        'jellygnite' => JellygniteMapper::class,
    ];

    /**
     * Tables of a translation module (Fluent) whose presence stops an apply run: values per locale are not
     * migrated.
     *
     * @config
     */
    private static array $localised_tables = [
        'SiteTree_Localised',
        'SiteConfig_Localised',
    ];

    public function getOptions(): array
    {
        return [
            new InputOption('source', null, InputOption::VALUE_REQUIRED, 'The module to migrate from: ' . implode(', ', $this->sourceNames())),
            new InputOption('apply', null, InputOption::VALUE_NONE, 'Write the changes (without it, a dry run that only reports)'),
            new InputOption('dry-run', null, InputOption::VALUE_NONE, 'Only report (the default)'),
            new InputOption('overwrite', null, InputOption::VALUE_NONE, 'Replace values that are already filled in here'),
        ];
    }

    protected function execute(InputInterface $input, PolyOutput $output): int
    {
        $source = trim((string) $input->getOption('source'));
        $mappers = array_filter((array) static::config()->get('mappers'));
        if ($source === '' || !isset($mappers[$source])) {
            $output->writeln($source === ''
                ? 'Give the module to migrate from with --source=<name>.'
                # The value is the user's: escaped, so neither the console nor the HTML output reads a tag in it
                : sprintf('Unknown source "%s".', OutputFormatter::escape($source)));
            $output->writeln('Sources: ' . implode(', ', $this->sourceNames()));
            return Command::INVALID;
        }
        if ($input->getOption('apply') && $input->getOption('dry-run')) {
            $output->writeln('Give either --apply or --dry-run, not both.');
            return Command::INVALID;
        }

        $mapper = Injector::inst()->create($mappers[$source]);
        if (!$mapper instanceof SeoMigrationMapper) {
            $output->writeln(sprintf('%s is not a %s.', $mappers[$source], SeoMigrationMapper::class));
            return Command::FAILURE;
        }

        $apply = (bool) $input->getOption('apply');
        $overwrite = (bool) $input->getOption('overwrite');
        $run = new MigrationRun($apply, $overwrite);

        # Fluent keeps page and site values per locale in _Localised tables, which the mappers do not read or
        # write: a run would copy the base values only and leave every translation as it was
        $localised = array_values(array_filter(
            (array) static::config()->get('localised_tables'),
            fn (string $table) => DB::get_schema()->hasTable($table)
        ));
        $fluentWarning = $localised ? sprintf(
            'Warning: %s found (Fluent): migrating per-locale values is not supported yet, so --apply is refused. '
            . 'Copy the localised values by hand.',
            implode(', ', $localised)
        ) : null;
        if ($fluentWarning && $apply) {
            $output->writeln($fluentWarning);
            return Command::FAILURE;
        }

        $output->writeln(sprintf('Migrating from %s.', $mapper->getTitle()));
        $output->writeln($apply
            ? 'Applying: values are written.'
            : 'Dry run: nothing is written. Run again with --apply to write.');
        if ($overwrite) {
            $output->writeln('Overwrite: values already filled in here are replaced.');
        }
        if ($fluentWarning) {
            $output->writeln($fluentWarning);
        }
        $output->writeln('');

        if ($apply) {
            # All or nothing: an error halfway rolls back what was written before it. Not withTransaction(),
            # which catches only Exception: an Error (a TypeError in a mapper) would leave the transaction open
            $db = DB::get_conn();
            $db->transactionStart();
            try {
                $mapper->migrate($run);
            } catch (Throwable $error) {
                $db->transactionRollback();
                throw $error;
            }
            $db->transactionEnd();
        } else {
            $mapper->migrate($run);
        }

        $this->report($run, $output);
        return Command::SUCCESS;
    }

    protected function report(MigrationRun $run, PolyOutput $output): void
    {
        $copied = 0;
        $conflicts = 0;
        $invalid = 0;
        foreach ($run->getEntries() as [$type, $entry]) {
            if ($entry instanceof MigrationResult) {
                $this->line($output, $entry->format($run->isApply(), $run->isOverwrite()));
                if ($entry->status === MigrationResult::COPY) {
                    $copied += $entry->copied;
                    $conflicts += $entry->conflicts;
                    $invalid += $entry->invalid;
                }
                continue;
            }
            $prefix = ['note' => 'Note: ', 'warning' => 'Warning: '][$type] ?? '';
            $this->line($output, $prefix . $entry);
        }
        $output->writeln('');
        $output->writeln(sprintf(
            '%s %d values; %d conflicts (%s); %d invalid. Old columns are left in place.',
            $run->isApply() ? 'Copied' : 'Would copy',
            $copied,
            $conflicts,
            $run->isOverwrite() ? 'replaced' : 'kept, --overwrite replaces them',
            $invalid
        ));
    }

    /**
     * A report line holds values from the database: escaped, so neither the console formatter nor (in a browser)
     * the HTML output reads a tag in them.
     */
    protected function line(PolyOutput $output, string $text): void
    {
        $output->writeln('  ' . OutputFormatter::escape($text));
    }

    /**
     * @return string[]
     */
    protected function sourceNames(): array
    {
        return array_keys(array_filter((array) static::config()->get('mappers')));
    }
}
