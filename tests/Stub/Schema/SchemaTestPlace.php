<?php

namespace Restruct\Silverstripe\SEO\Tests\Stub\Schema;

use Restruct\Silverstripe\SEO\Extensions\SchemaSource;
use SilverStripe\Dev\TestOnly;
use SilverStripe\ORM\DataObject;

/**
 * A venue with its address in separate fields, mapped to a Place with a nested PostalAddress. It has no link of
 * its own, so its @id is built on the link of the page being rendered.
 *
 * @property string $Title
 * @property string $Street
 * @property string $PostalCode
 * @property string $City
 * @property string $Country
 */
class SchemaTestPlace extends DataObject implements TestOnly
{
    private static string $table_name = 'SchemaTestPlace';

    private static array $db = [
        'Title' => 'Varchar',
        'Street' => 'Varchar',
        'PostalCode' => 'Varchar',
        'City' => 'Varchar',
        'Country' => 'Varchar(2)',
    ];

    private static array $belongs_many_many = [
        'Events' => SchemaTestEvent::class,
    ];

    private static array $extensions = [
        SchemaSource::class,
    ];

    private static string $schema_type = 'Place';

    private static array $schema_map = [
        'name' => 'Title',
        'address' => [
            '@type' => 'PostalAddress',
            'streetAddress' => 'Street',
            'postalCode' => 'PostalCode',
            'addressLocality' => 'City',
            'addressCountry' => 'Country',
        ],
    ];
}
