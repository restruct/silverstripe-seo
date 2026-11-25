<?php

namespace Restruct\Silverstripe\SEO\Migration;

/**
 * Moves one other SEO module's data into this module's columns.
 *
 * A mapper describes, per old column, whether it is already this module's own column, has a new home (copied by
 * {@see MigrationRun::copyColumn()}), or has none (reported). It reads the old data with raw SQL, because the old
 * module's classes are usually no longer installed, and it writes only through the {@see MigrationRun}, which
 * holds the dry-run and overwrite rules and the report.
 *
 * Mappers are registered by source name in `MigrateFromSeoModuleTask.mappers`, so a project can add its own.
 */
interface SeoMigrationMapper
{
    /**
     * The source module, for the report header, e.g. "hubertusanton/silverstripe-seo".
     */
    public function getTitle(): string;

    public function migrate(MigrationRun $run): void;
}
