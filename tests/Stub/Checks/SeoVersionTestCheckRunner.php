<?php

namespace Restruct\Silverstripe\SEO\Tests\Stub\Checks;

use Restruct\Silverstripe\SEO\Checks\CheckRunner;
use SilverStripe\Dev\TestOnly;

/**
 * A runner that reports a module version the test sets, to stand in for a site that updated the module.
 */
class SeoVersionTestCheckRunner extends CheckRunner implements TestOnly
{
    public static string $version = '1.0.0';

    public function moduleVersion(): string
    {
        return static::$version;
    }
}
