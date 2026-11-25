<?php

namespace Restruct\Silverstripe\SEO\Tests\Stub\Schema;

use SilverStripe\Dev\TestOnly;
use SilverStripe\ORM\DataObject;

/**
 * A record whose values come from getters and cast methods rather than plain columns, the way templates read
 * them: an overriding getTitle(), a getter without a column, and a method cast as HTMLText.
 *
 * @property string $Title
 */
class SchemaTestGetterItem extends DataObject implements TestOnly
{
    private static string $table_name = 'SchemaTestGetterItem';

    private static array $db = [
        'Title' => 'Varchar',
    ];

    private static array $casting = [
        'Teaser' => 'HTMLText',
    ];

    public function getTitle(): string
    {
        return 'Overridden ' . $this->getField('Title');
    }

    public function getTagline(): string
    {
        return 'Hello there';
    }

    public function Teaser(): string
    {
        return '<p>Fish &amp; chips</p>';
    }
}
