<?php

namespace Restruct\Silverstripe\SEO\Tests\Stub;

use SilverStripe\Dev\TestOnly;

/**
 * A page whose controller sets SEO response headers itself, as project code does (an X-Robots-Tag on some
 * actions, a Link header of its own).
 */
class SeoHeaderTestPage extends SeoTestPage implements TestOnly
{
    private static string $table_name = 'SeoHeaderTestPage';

    private static string $controller_name = SeoHeaderTestPageController::class;
}
