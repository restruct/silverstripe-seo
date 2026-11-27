<?php

namespace Restruct\Silverstripe\SEO\Tests\Stub\Checks;

use Restruct\Silverstripe\SEO\Schema\SchemaContext;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Dev\TestOnly;
use SilverStripe\ORM\HasManyList;

/**
 * A page whose template puts the page title in an H1 inside `<main>`, renders a list of content blocks after the
 * content (the way Elemental areas are rendered) and has a header and footer outside `<main>`, so the checks can
 * be tested on what the site renders rather than on CMS fields.
 *
 * @property bool $HideH1
 * @property bool $IncompleteEvent
 * @property string $ExtraHead
 * @property string $RedirectTo
 * @method HasManyList<SeoCheckTestBlock> Blocks()
 */
class SeoCheckTestPage extends SiteTree implements TestOnly
{
    private static string $table_name = 'SeoCheckTestPage';

    private static string $controller_name = SeoCheckTestPageController::class;

    private static array $db = [
        # Leaves the template's H1 out, for the heading checks
        'HideH1' => 'Boolean',
        # Makes the page an Event without a start date, for the structured-data check
        'IncompleteEvent' => 'Boolean',
        # Printed as-is in the head, for markup a theme or another module adds there (JSON-LD, say)
        'ExtraHead' => 'Text',
        # The controller redirects: 'self' to the page's own URL, anything else to that URL, for the render entry
        'RedirectTo' => 'Varchar(255)',
    ];

    private static array $has_many = [
        'Blocks' => SeoCheckTestBlock::class,
    ];

    private static array $owns = [
        'Blocks',
    ];

    private static array $cascade_deletes = [
        'Blocks',
    ];

    /**
     * An Event lacks startDate and location, which are required: the node is left out and a warning is given.
     */
    public function provideSchemaData(SchemaContext $ctx): ?array
    {
        if (!$this->IncompleteEvent) {
            return null;
        }
        return ['@type' => 'Event', '@id' => $ctx->pageId('event'), 'name' => $this->Title];
    }
}
