<?php

namespace Restruct\Silverstripe\SEO\Tests\Stub\Schema;

use SilverStripe\Dev\TestOnly;
use SilverStripe\ORM\DataObject;

/**
 * A ticket type of an event (has_many), NOT a schema source itself: the event's map describes it as an Offer
 * with a nested map that reads from the relation (`from: Tickets`).
 *
 * @property string $Title
 * @property float $Price
 */
class SchemaTestTicket extends DataObject implements TestOnly
{
    private static string $table_name = 'SchemaTestTicket';

    private static array $db = [
        'Title' => 'Varchar',
        'Price' => 'Decimal(9,2)',
    ];

    private static array $has_one = [
        'Event' => SchemaTestEvent::class,
    ];
}
