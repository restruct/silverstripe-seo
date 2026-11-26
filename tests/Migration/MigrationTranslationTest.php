<?php

namespace Restruct\Silverstripe\SEO\Tests\Migration;

use Restruct\Silverstripe\SEO\Tasks\MigrateFromSeoModuleTask;
use SilverStripe\i18n\i18n;
use Symfony\Component\Console\Command\Command;

/**
 * `sake tasks:seo-migrate` under a Dutch locale prints Dutch only: the task's own lines, the report lines and
 * the mappers' hints and notes. Labels (column names) and core's "Running task" frame stay as they are.
 */
class MigrationTranslationTest extends SeoMigrationTestCase
{
    /**
     * English fragments of the task's own output; none may be left under nl_NL.
     */
    private const ENGLISH = [
        'Migrating from',
        'Dry run',
        'nothing is written',
        'Run again',
        'would copy',
        'Would copy',
        'read 1',
        'already same',
        'conflicts',
        'invalid',
        'no equivalent',
        'dropped',
        'values left in place',
        'not found',
        'skipped',
        'Old columns are left in place',
        'Note:',
        'focus keyphrases',
        'og:type is set per page class',
        'put the verification tag',
        'no per-page switch',
        'no longer read',
        'the title is the meta title',
        'no structured data',
        'Unknown source',
        'Sources:',
        'Give the module',
        'not both',
        'Migrate SEO data',
    ];

    protected function assertNoEnglish(string $output): void
    {
        foreach (self::ENGLISH as $fragment) {
            $this->assertStringNotContainsString($fragment, $output, "English left in:\n$output");
        }
    }

    protected function runDutch(?string $source, array $options = [], int $expected = Command::SUCCESS): string
    {
        return i18n::with_locale('nl_NL', fn () => $this->runMigration($source, $options, $expected));
    }

    public function testAHubertusantonDryRunIsDutch(): void
    {
        $this->addOldStagedColumn('Page', 'SEOPageSubject', 'varchar(256)');
        $this->addOldStagedColumn('Page', 'SEOSocialType', 'varchar(50)');
        $this->addOldStagedColumn('Page', 'SEOHideSocialData', 'tinyint(1) unsigned NOT NULL DEFAULT 0');
        $this->addOldStagedColumn('Page', 'SEOSocialImageID', 'int NOT NULL DEFAULT 0');
        $this->addOldColumn('SiteConfig', 'GoogleWebmasterMetaTag', 'varchar(512)');
        $page = $this->publishedPage('Courses');
        $this->setRaw('Page', $page->ID, ['SEOSocialImageID' => $this->createTestImage('draft.png')->ID, 'SEOHideSocialData' => 1]);

        $output = $this->runDutch('hubertusanton');
        $this->assertNoEnglish($output);
        $this->assertStringContainsString('Proefdraai', $output);
    }

    public function testALiveSeoDryRunIsDutch(): void
    {
        $this->addOldStagedColumn('SiteTree', 'MetaKeywords', 'varchar(255)');
        $this->addOldStagedColumn('SiteTree', 'SEOFBAuthorlink', 'varchar(255)');
        $this->addOldColumn('SiteConfig', 'SEOTitleTemplate', 'varchar(255)');

        $output = $this->runDutch('liveseo');
        $this->assertNoEnglish($output);
    }

    public function testRefusedOptionsAreDutch(): void
    {
        $this->assertNoEnglish($this->runDutch(null, [], Command::INVALID));
        $this->assertNoEnglish($this->runDutch('nosuchmodule', [], Command::INVALID));
        $this->assertNoEnglish($this->runDutch('liveseo', ['--apply' => true, '--dry-run' => true], Command::INVALID));
    }

    public function testTheTitleAndOptionsAreDutch(): void
    {
        $texts = i18n::with_locale('nl_NL', function () {
            $task = MigrateFromSeoModuleTask::create();
            $texts = [$task->getTitle(), MigrateFromSeoModuleTask::getDescription()];
            foreach ($task->getOptions() as $option) {
                $texts[] = $option->getDescription();
            }
            return implode("\n", $texts);
        });
        foreach (['Migrate SEO data', 'Copy page and site', 'The module to migrate', 'Write the changes', 'Only report', 'Replace values'] as $fragment) {
            $this->assertStringNotContainsString($fragment, $texts);
        }
    }
}
