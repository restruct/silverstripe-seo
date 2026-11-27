<?php

namespace Restruct\Silverstripe\SEO\Tests;

use Restruct\Silverstripe\SEO\Extensions\SeoSiteTreeExtension;
use Restruct\Silverstripe\SEO\Tests\Stub\SeoMetadataToggleTestExtension;
use Restruct\Silverstripe\SEO\Tests\Stub\SeoOtherMetaTitleTestExtension;
use Restruct\Silverstripe\SEO\Tests\Stub\SeoTestPage;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Dev\SapphireTest;

/**
 * The SEO tab next to fields other modules put in core's Metadata toggle: core's own fields move to the tab,
 * the toggle stays for what is left in it. The other modules' extensions run before this module's, as they
 * would when their config is loaded first.
 */
class SeoTabPlacementTest extends SapphireTest
{
    protected $usesDatabase = true;

    protected static $extra_dataobjects = [
        SeoTestPage::class,
    ];

    # Moved behind the stand-ins, so they find core's toggle as it is before this module changes it
    protected static $illegal_extensions = [
        SiteTree::class => [
            SeoSiteTreeExtension::class,
        ],
    ];

    protected static $required_extensions = [
        SeoTestPage::class => [
            SeoMetadataToggleTestExtension::class,
            SeoOtherMetaTitleTestExtension::class,
            SeoSiteTreeExtension::class,
        ],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        SeoTestPage::config()->merge('seo_meta_title_field_providers', [SeoOtherMetaTitleTestExtension::class]);
    }

    public function testTheMetadataToggleStaysForAnotherModulesField(): void
    {
        $page = SeoTestPage::create(['Title' => 'About us']);
        $page->write();
        $fields = $page->getCMSFields();

        $toggle = $fields->fieldByName('Root.Main.Metadata');
        $this->assertNotNull($toggle, 'kept: another module still has a field in it');
        $names = array_values(array_map(fn ($f) => $f->getName(), $toggle->getChildren()->toArray()));
        $this->assertSame(['OtherModuleMeta'], $names, "core's fields moved out, the other module's stayed");

        $tab = $fields->fieldByName('Root.SEO');
        $this->assertNotNull($tab->getChildren()->dataFieldByName('MetaDescription'));
        $this->assertNotNull($tab->getChildren()->dataFieldByName('ExtraMeta'));
    }

    public function testAnotherModulesMetaTitleMovesAlongNextToTheDescription(): void
    {
        $page = SeoTestPage::create(['Title' => 'About us']);
        $page->write();
        $tab = $page->getCMSFields()->fieldByName('Root.SEO');

        $names = array_values(array_map(fn ($f) => $f->getName(), $tab->getChildren()->getDataFields()));
        $this->assertSame(array_search('MetaDescription', $names) - 1, array_search('MetaTitle', $names));
        $this->assertTrue($tab->getChildren()->dataFieldByName('MetaTitle')->hasClass('other-module-meta-title'));
    }
}
