<?php

namespace Restruct\Silverstripe\SEO\Tests\Stub;

use SilverStripe\Core\Extension;
use SilverStripe\Dev\TestOnly;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\TextField;

/**
 * Stands in for another module that adds its own MetaTitle field to pages, the way kinglozzer/metatitle does
 * (a TextField inserted before MetaDescription), so the coexistence guard can be tested without installing it.
 */
class SeoOtherMetaTitleTestExtension extends Extension implements TestOnly
{
    private static array $db = [
        'MetaTitle' => 'Varchar(255)',
    ];

    protected function updateCMSFields(FieldList $fields): void
    {
        $fields->insertBefore(
            'MetaDescription',
            TextField::create('MetaTitle', 'Title')->addExtraClass('other-module-meta-title')
        );
    }
}
