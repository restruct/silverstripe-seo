<?php

namespace Restruct\Silverstripe\SEO\Tests\Stub\Migration;

use Restruct\Silverstripe\SEO\Migration\MigrationRun;
use Restruct\Silverstripe\SEO\Migration\SeoMigrationMapper;
use SilverStripe\Dev\TestOnly;
use SilverStripe\SiteConfig\SiteConfig;
use TypeError;

/**
 * Writes a value, then fails with an Error (not an Exception), as a mapper bug would.
 */
class ThrowingTestMapper implements SeoMigrationMapper, TestOnly
{
    public function getTitle(): string
    {
        return 'a failing test mapper';
    }

    public function migrate(MigrationRun $run): void
    {
        $run->copyValue('Suffix', 'SiteConfig', 'SeoTitleSuffix', (int) SiteConfig::current_site_config()->ID, 'Written');
        throw new TypeError('A mapper bug halfway through the run');
    }
}
