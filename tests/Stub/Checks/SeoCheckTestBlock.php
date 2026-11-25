<?php

namespace Restruct\Silverstripe\SEO\Tests\Stub\Checks;

use SilverStripe\Dev\TestOnly;
use SilverStripe\ORM\DataObject;
use SilverStripe\Versioned\Versioned;

/**
 * A content block, Elemental-like: versioned, owned by its page, with an HTML field rendered by the page template.
 * Its links are tracked by core's link tracking (applied to every DataObject), as an Elemental block's are.
 *
 * @property string $Title
 * @property string $Content
 * @property int $Sort
 * @property int $PageID
 */
class SeoCheckTestBlock extends DataObject implements TestOnly
{
    private static string $table_name = 'SeoCheckTestBlock';

    private static array $db = [
        'Title' => 'Varchar(255)',
        'Content' => 'HTMLText',
        'Sort' => 'Int',
    ];

    private static array $has_one = [
        'Page' => SeoCheckTestPage::class,
    ];

    private static array $extensions = [
        Versioned::class,
    ];

    private static string $default_sort = 'Sort';
}
