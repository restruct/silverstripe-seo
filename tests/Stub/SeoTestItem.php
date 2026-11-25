<?php

namespace Restruct\Silverstripe\SEO\Tests\Stub;

use SilverStripe\Assets\Image;
use SilverStripe\Control\Controller;
use SilverStripe\Control\Director;
use SilverStripe\Dev\TestOnly;
use SilverStripe\ORM\DataObject;

/**
 * A plain record shown on a holder page's sub-action (/holder/item/12), WITHOUT the SeoSubject interface: the
 * module has to read it by duck-typing (Title, MetaTitle, MetaDescription, Content, AbsoluteLink, OGImage).
 *
 * @property string $Title
 * @property string $MetaTitle
 * @property string $MetaDescription
 * @property string $Content
 * @property string $LinkQuery
 */
class SeoTestItem extends DataObject implements TestOnly
{
    private static string $table_name = 'SeoTestItem';

    private static array $db = [
        'Title' => 'Varchar',
        'MetaTitle' => 'Varchar',
        'MetaDescription' => 'Text',
        'Content' => 'HTMLText',
        # Extra query string on the item's own link, to check what the canonical keeps and drops
        'LinkQuery' => 'Varchar',
    ];

    private static array $has_one = [
        'Holder' => SeoTestPage::class,
        'OGImage' => Image::class,
    ];

    public function Link(): string
    {
        $link = Controller::join_links($this->Holder()->Link(), 'item', $this->ID);
        return $this->LinkQuery ? $link . '?' . $this->LinkQuery : $link;
    }

    public function AbsoluteLink(): string
    {
        return Director::absoluteURL($this->Link());
    }
}
