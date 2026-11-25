<?php

namespace Restruct\Silverstripe\SEO\Tests;

use Restruct\Silverstripe\SEO\Extensions\SeoSiteTreeExtension;
use Restruct\Silverstripe\SEO\Tests\Stub\SeoOtherMetaTitleTestExtension;
use Restruct\Silverstripe\SEO\Tests\Stub\SeoTestPage;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Dev\SapphireTest;

/**
 * kinglozzer/metatitle adds a MetaTitle field of its own, on the same column. With it installed this module must
 * not add a second field (which, on SS6, would replace that module's own). A stub extension stands in for that
 * module, registered through the same config list.
 */
class SeoMetaTitleCoexistenceTest extends SapphireTest
{
    # Counts every occurrence of a field, also one present twice
    use SeoFieldCounting;

    protected $usesDatabase = true;

    protected static $extra_dataobjects = [
        SeoTestPage::class,
    ];

    # SS6's FieldList::insertBefore() removes a field of the same name first, so the extension that runs last
    # decides which MetaTitle field is left. This module is moved behind the stand-in, the order in which its own
    # field would replace the other module's.
    protected static $illegal_extensions = [
        SiteTree::class => [
            SeoSiteTreeExtension::class,
        ],
    ];

    protected static $required_extensions = [
        SeoTestPage::class => [
            SeoOtherMetaTitleTestExtension::class,
            SeoSiteTreeExtension::class,
        ],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        SeoTestPage::config()->merge('seo_meta_title_field_providers', [SeoOtherMetaTitleTestExtension::class]);
    }

    public function testNoSecondMetaTitleFieldWhenAnotherModuleProvidesOne(): void
    {
        $page = SeoTestPage::create(['Title' => 'About us']);
        $page->write();

        $fields = $page->getCMSFields();
        $this->assertSame(1, $this->countFields($fields)['MetaTitle'] ?? 0, 'MetaTitle appears exactly once');
        $this->assertTrue(
            $fields->dataFieldByName('MetaTitle')->hasClass('other-module-meta-title'),
            'the other module\'s field is the one kept'
        );
        # The rest of this module's fields are still there
        $this->assertNotNull($fields->dataFieldByName('CanonicalURL'));
    }

    public function testKinglozzerMetaTitleIsListedByDefault(): void
    {
        $this->assertContains(
            'Kinglozzer\\SilverStripeMetaTitle\\MetaTitleExtension',
            (array) SeoTestPage::config()->get('seo_meta_title_field_providers')
        );
    }
}
