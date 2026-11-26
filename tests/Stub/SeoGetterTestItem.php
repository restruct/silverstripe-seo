<?php

namespace Restruct\Silverstripe\SEO\Tests\Stub;

use SilverStripe\Control\Controller;
use SilverStripe\Control\Director;
use SilverStripe\Dev\TestOnly;
use SilverStripe\ORM\DataObject;

/**
 * A record shown on a holder page (/holder/getteritem/12) that decides its SEO values in code: a getter override
 * of MetaTitle and MetaRobotsNoIndex that ignores the columns, and a nofollow decided by a getter alone (no
 * column).
 *
 * @property string $Title
 * @property string $Code
 * @property string $MetaTitle
 * @property bool $MetaRobotsNoIndex
 * @property bool $Archived
 * @property bool $Sponsored
 */
class SeoGetterTestItem extends DataObject implements TestOnly
{
    private static string $table_name = 'SeoGetterTestItem';

    private static array $db = [
        'Title' => 'Varchar',
        'Code' => 'Varchar(20)',
        'MetaTitle' => 'Varchar',
        'MetaRobotsNoIndex' => 'Boolean',
        'Archived' => 'Boolean',
        'Sponsored' => 'Boolean',
    ];

    private static array $has_one = [
        'Holder' => SeoTestPage::class,
    ];

    public function getMetaTitle(): string
    {
        return 'Run ' . $this->Code;
    }

    public function getMetaRobotsNoIndex(): bool
    {
        return (bool) $this->Archived;
    }

    # No MetaRobotsNoFollow column: the getter alone is the record's own setting
    public function getMetaRobotsNoFollow(): bool
    {
        return (bool) $this->Sponsored;
    }

    public function Link(): string
    {
        return Controller::join_links($this->Holder()->Link(), 'getteritem', $this->ID);
    }

    public function AbsoluteLink(): string
    {
        return Director::absoluteURL($this->Link());
    }
}
