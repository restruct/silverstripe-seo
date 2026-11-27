<?php

namespace Restruct\Silverstripe\SEO\Tests\Stub;

use SilverStripe\Dev\TestOnly;
use SilverStripe\Core\Extension;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\TextField;

/**
 * Stands in for another module that puts a field of its own in core's Metadata toggle, so a test can check the
 * toggle is kept when moving core's fields out of it does not leave it empty.
 */
class SeoMetadataToggleTestExtension extends Extension implements TestOnly
{
    protected function updateCMSFields(FieldList $fields): void
    {
        $fields->addFieldToTab('Root.Main.Metadata', TextField::create('OtherModuleMeta', 'Other module'));
    }
}
