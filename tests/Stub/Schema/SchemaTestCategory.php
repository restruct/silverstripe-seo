<?php

namespace Restruct\Silverstripe\SEO\Tests\Stub\Schema;

use SilverStripe\Dev\TestOnly;
use SilverStripe\ORM\DataObject;

/**
 * A plain has_one target, read through a dotted path (`Category.Title`).
 *
 * @property string $Title
 */
class SchemaTestCategory extends DataObject implements TestOnly
{
    private static string $table_name = 'SchemaTestCategory';

    private static array $db = [
        'Title' => 'Varchar',
    ];
}
