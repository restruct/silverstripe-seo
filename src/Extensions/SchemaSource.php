<?php

namespace Restruct\Silverstripe\SEO\Extensions;

use Restruct\Silverstripe\SEO\Schema\SchemaContext;
use Restruct\Silverstripe\SEO\Schema\SchemaMapper;
use SilverStripe\Core\Extension;
use SilverStripe\ORM\DataObject;

/**
 * Makes a page type or DataObject a source of structured data, described in YAML; no table of its own, the
 * record's own fields are the data:
 *
 * ```yml
 * App\Model\Event:
 *   extensions:
 *     - Restruct\Silverstripe\SEO\Extensions\SchemaSource
 *   schema_type: Event
 *   schema_map:
 *     name: Title
 *     startDate: { date: StartDate, time: StartTime }
 *     location: Venues
 *     organizer: { ref: organization }
 * ```
 *
 * The value kinds a map understands are listed on {@see SchemaMapper}. A record class can instead (or as well)
 * define `provideSchemaData(SchemaContext $ctx): ?array`, which then wins over the map.
 *
 * @extends Extension<DataObject>
 */
class SchemaSource extends Extension
{
    /**
     * The schema.org type of the record's node (`Event`, `JobPosting`, ...). Empty: the record supplies nothing
     * through the map.
     */
    private static string $schema_type = '';

    /**
     * Property name => value specification. Keyed, so a project's YAML can add or override single properties;
     * set a property to null to drop one the module or a parent class mapped.
     */
    private static array $schema_map = [];

    /**
     * A fixed site-wide `@id` instead of the record rule: `organization` gives `<base URL>/#organization`. Set on
     * SiteConfig, so the organisation has one id every node can point at.
     */
    private static string $schema_id = '';

    /**
     * Relations or methods whose records are added to the page as top-level nodes of their own when this record
     * is the page (or the record a page shows): a course page listing its upcoming course dates, say.
     */
    private static array $schema_includes = [];

    /**
     * At most this many included records per page view, over all of `schema_includes`; 0 for no limit. Each is
     * mapped on every page view, as nothing is cached.
     */
    private static int $schema_includes_limit = 50;

    /**
     * Methods a `schema_map` may call besides database fields, relations, `get<Name>()` getters and methods in
     * `casting`. Any other name in a map is not called and shows up as a warning. A list, so a project's YAML
     * adds to it.
     */
    private static array $schema_allowed_methods = [];

    /**
     * Whether a record of this class, reached through a relation, `schema_includes` or `schema_item_list`, must
     * pass canView() for an anonymous visitor. Null: for pages always, for other classes when they define
     * canView() themselves (see SchemaMapper::isVisible()).
     */
    private static ?bool $schema_check_can_view = null;

    /**
     * The record's nodes, for use outside a page render (a report, a test). The context says which page they
     * are described on.
     *
     * @return array[]
     */
    public function getSchemaNodes(SchemaContext $context): array
    {
        return SchemaMapper::create($context)->nodesFor($this->getOwner());
    }
}
