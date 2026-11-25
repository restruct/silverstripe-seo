<?php

namespace Restruct\Silverstripe\SEO\Tests\Stub\Schema;

use Restruct\Silverstripe\SEO\Tests\Stub\SeoTestPage;

/**
 * A listing page: a CollectionPage with an ItemList of its events, which shows one event on /holder/event/<ID>
 * and declares it as the SEO subject there.
 */
class SchemaTestHolderPage extends SeoTestPage
{
    private static string $table_name = 'SchemaTestHolderPage';

    private static string $controller_name = SchemaTestHolderPageController::class;

    private static array $has_many = [
        'Events' => SchemaTestEvent::class,
    ];

    private static string $schema_webpage_type = 'CollectionPage';

    private static string $schema_item_list = 'Events';
}
