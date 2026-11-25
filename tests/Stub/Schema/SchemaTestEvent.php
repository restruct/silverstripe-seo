<?php

namespace Restruct\Silverstripe\SEO\Tests\Stub\Schema;

use Restruct\Silverstripe\SEO\Extensions\SchemaSource;
use SilverStripe\Assets\Image;
use SilverStripe\Control\Controller;
use SilverStripe\Control\Director;
use SilverStripe\Dev\TestOnly;
use SilverStripe\ORM\DataObject;

/**
 * An event in the shape sites keep them: date and time in separate fields, several venues (many_many), ticket
 * types (has_many), a category, and a holder page that shows it on /holder/event/<ID>. The map uses every kind
 * of value: field, method, dotted relation path, literal, date + time pair, nested map with `from`, a relation to
 * other schema sources, and a reference to the organisation.
 *
 * @property string $Title
 * @property string $Description
 * @property string $StartDate
 * @property string $StartTime
 * @property string $EndDate
 * @property string $EndTime
 * @property string $Language
 * @method SchemaTestHolderPage Holder()
 */
class SchemaTestEvent extends DataObject implements TestOnly
{
    private static string $table_name = 'SchemaTestEvent';

    private static array $db = [
        'Title' => 'Varchar',
        'Description' => 'HTMLText',
        'StartDate' => 'Date',
        'StartTime' => 'Time',
        'EndDate' => 'Date',
        'EndTime' => 'Time',
        'Language' => 'Varchar(10)',
    ];

    private static array $has_one = [
        'Holder' => SchemaTestHolderPage::class,
        'Category' => SchemaTestCategory::class,
        'Image' => Image::class,
    ];

    private static array $has_many = [
        'Tickets' => SchemaTestTicket::class,
    ];

    private static array $many_many = [
        'Places' => SchemaTestPlace::class,
    ];

    private static array $extensions = [
        SchemaSource::class,
    ];

    private static string $schema_type = 'Event';

    private static array $schema_map = [
        'name' => 'Title',
        'description' => 'Description',
        'url' => 'AbsoluteLink',
        'startDate' => ['date' => 'StartDate', 'time' => 'StartTime'],
        'endDate' => ['date' => 'EndDate', 'time' => 'EndTime'],
        'eventStatus' => ['literal' => 'https://schema.org/EventScheduled'],
        'inLanguage' => 'Language',
        'keywords' => 'Category.Title',
        'image' => 'Image',
        'organizer' => ['ref' => 'organization'],
        'location' => 'Places',
        'offers' => [
            '@type' => 'Offer',
            'from' => 'Tickets',
            'name' => 'Title',
            'price' => 'Price',
            'priceCurrency' => ['literal' => 'EUR'],
        ],
    ];

    public function Link(): string
    {
        return Controller::join_links($this->Holder()->Link(), 'event', $this->ID);
    }

    public function AbsoluteLink(): string
    {
        return Director::absoluteURL($this->Link());
    }
}
