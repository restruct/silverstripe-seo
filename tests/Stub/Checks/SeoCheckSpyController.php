<?php

namespace Restruct\Silverstripe\SEO\Tests\Stub\Checks;

use Restruct\Silverstripe\SEO\Control\SeoCheckController;
use SilverStripe\Dev\TestOnly;

/**
 * Records when the endpoint lets go of the session lock (a no-op in the CLI anyway: no PHP session is active).
 */
class SeoCheckSpyController extends SeoCheckController implements TestOnly
{
    public static array $calls = [];

    protected function releaseSessionLock(): void
    {
        static::$calls[] = 'release';
        parent::releaseSessionLock();
    }
}
