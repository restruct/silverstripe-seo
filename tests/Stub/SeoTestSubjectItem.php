<?php

namespace Restruct\Silverstripe\SEO\Tests\Stub;

use Restruct\Silverstripe\SEO\Model\SeoSubject;
use SilverStripe\Assets\Image;
use SilverStripe\Control\Director;
use SilverStripe\Dev\TestOnly;
use SilverStripe\ORM\DataObject;

/**
 * A record that answers for itself through the SeoSubject interface, so none of the duck-typed fields are used.
 *
 * @property string $Title
 * @property bool $Hidden
 */
class SeoTestSubjectItem extends DataObject implements TestOnly, SeoSubject
{
    private static string $table_name = 'SeoTestSubjectItem';

    private static array $db = [
        'Title' => 'Varchar',
        'Hidden' => 'Boolean',
    ];

    public function getSeoTitle(): string
    {
        return 'Interface title';
    }

    public function getSeoDescription(): string
    {
        return 'Interface description.';
    }

    public function getSeoCanonicalURL(): ?string
    {
        return Director::absoluteURL('/elsewhere/' . $this->ID);
    }

    public function getSeoImage(): ?Image
    {
        return null;
    }

    public function getSeoRobotsDirectives(): ?array
    {
        # null = inherit from the page; an array (even empty) = the record decides
        return $this->Hidden ? ['noindex', 'nofollow'] : [];
    }
}
