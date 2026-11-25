<?php

namespace Restruct\Silverstripe\SEO\Tests\Migration;

use Restruct\Silverstripe\SEO\Tasks\MigrateFromSeoModuleTask;
use Restruct\Silverstripe\SEO\Tests\Stub\Migration\ThrowingTestMapper;
use SilverStripe\Core\Config\Config;
use SilverStripe\ORM\DB;
use TypeError;

/**
 * An apply run is all or nothing, also when a mapper fails with an Error rather than an Exception.
 */
class TransactionMigrationTest extends SeoMigrationTestCase
{
    public function testAnErrorHalfwayRollsBackWhatWasWritten(): void
    {
        Config::modify()->merge(MigrateFromSeoModuleTask::class, 'mappers', ['throwing' => ThrowingTestMapper::class]);
        $thrown = null;
        try {
            $this->runMigration('throwing', ['--apply' => true]);
        } catch (TypeError $error) {
            $thrown = $error;
        }
        # Read on the same connection, before any clean-up: an open transaction shows its own writes
        $depth = DB::get_conn()->transactionDepth();
        $suffix = $this->raw('SiteConfig', 'SeoTitleSuffix', $this->siteConfigID());
        # Leave no transaction open for the tests that follow, whatever the outcome
        while (DB::get_conn()->transactionDepth() > 0) {
            DB::get_conn()->transactionRollback();
        }

        $this->assertInstanceOf(TypeError::class, $thrown, 'The error reaches the caller');
        $this->assertSame(0, $depth, 'The transaction is closed');
        $this->assertEmpty($suffix, 'The value written before the error is rolled back');
    }
}
