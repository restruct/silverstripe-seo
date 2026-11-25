<?php

namespace Restruct\Silverstripe\SEO\Tests\Stub\Schema;

use Restruct\Silverstripe\SEO\Extensions\SchemaSource;
use Restruct\Silverstripe\SEO\Tests\Stub\SeoTestPage;

/**
 * A vacancy that is a page of its own, mapped to a JobPosting whose hiring organisation is the site's
 * Organization node, by reference.
 *
 * @property string $DatePosted
 * @property string $ValidThrough
 * @property string $EmploymentType
 * @property string $City
 */
class SchemaTestJobPage extends SeoTestPage
{
    private static string $table_name = 'SchemaTestJobPage';

    private static array $db = [
        'DatePosted' => 'Date',
        'ValidThrough' => 'Datetime',
        'EmploymentType' => 'Varchar',
        'City' => 'Varchar',
    ];

    private static array $extensions = [
        SchemaSource::class,
    ];

    private static string $schema_type = 'JobPosting';

    private static array $schema_map = [
        'title' => 'Title',
        'description' => 'Content',
        'datePosted' => 'DatePosted',
        'validThrough' => 'ValidThrough',
        'employmentType' => 'EmploymentType',
        'hiringOrganization' => ['ref' => 'organization'],
        'jobLocation' => [
            '@type' => 'Place',
            'address' => [
                '@type' => 'PostalAddress',
                'addressLocality' => 'City',
                'addressCountry' => ['literal' => 'NL'],
            ],
        ],
    ];
}
