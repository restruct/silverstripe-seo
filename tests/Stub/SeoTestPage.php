<?php

namespace Restruct\Silverstripe\SEO\Tests\Stub;

use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Dev\TestOnly;

/**
 * A page class owned by the test suite, so the tests do not depend on the host project having an `App\Page` or
 * a `Page` class, or on what that class's template looks like.
 */
class SeoTestPage extends SiteTree implements TestOnly
{
    private static string $table_name = 'SeoTestPage';

    private static string $controller_name = SeoTestPageController::class;
}
