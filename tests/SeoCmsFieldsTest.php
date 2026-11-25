<?php

namespace Restruct\Silverstripe\SEO\Tests;

use Restruct\Silverstripe\SEO\Tests\Stub\SeoTestPage;
use SilverStripe\CMS\Model\RedirectorPage;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\FormField;
use SilverStripe\SiteConfig\SiteConfig;

/**
 * SS6's SiteTree scaffolds every database field an extension adds, before updateCMSFields runs. A module that
 * then adds its own field for the same column gets "a field called X appears twice", or, if it guards against
 * that, the field shows up twice in two places. These tests pin that every SEO field appears exactly once, where
 * it is meant to be.
 */
class SeoCmsFieldsTest extends SapphireTest
{
    protected $usesDatabase = true;

    protected static $extra_dataobjects = [
        SeoTestPage::class,
    ];

    private const PAGE_FIELDS = [
        'MetaTitle',
        'MetaDescription',
        'MetaRobotsNoIndex',
        'MetaRobotsNoFollow',
        'MetaRobotsNoSnippet',
        'MetaRobotsNoCache',
        'CanonicalURL',
        'SEOFBdescription',
        'OGImage',
    ];

    /**
     * Every data field in the list, with its tab path, so a field present in two tabs is counted twice.
     *
     * @return array<string,int>
     */
    private function countFields(FieldList $fields): array
    {
        $counts = [];
        foreach ($fields->dataFields() as $field) {
            /** @var FormField $field */
            $counts[$field->getName()] = ($counts[$field->getName()] ?? 0) + 1;
        }
        return $counts;
    }

    public function testEveryPageFieldAppearsExactlyOnce(): void
    {
        $page = SeoTestPage::create(['Title' => 'About us']);
        $page->write();

        # Would throw "appears twice" if a scaffolded field collided with an explicitly added one
        $fields = $page->getCMSFields();
        $counts = $this->countFields($fields);

        foreach (self::PAGE_FIELDS as $name) {
            $this->assertSame(1, $counts[$name] ?? 0, "$name should appear exactly once");
        }
    }

    public function testFieldsLiveInTheMetadataToggleBelowContent(): void
    {
        $page = SeoTestPage::create(['Title' => 'About us']);
        $page->write();
        $fields = $page->getCMSFields();

        $toggle = $fields->fieldByName('Root.Main.Metadata');
        $this->assertNotNull($toggle, 'core\'s Metadata toggle is kept, so code that looks it up still finds it');
        foreach (self::PAGE_FIELDS as $name) {
            $this->assertNotNull($toggle->getChildren()->dataFieldByName($name), "$name should be in the Metadata toggle");
        }

        # Meta title goes right before the description, where editors look for it
        $names = array_map(fn ($f) => $f->getName(), $toggle->getChildren()->dataFields());
        $names = array_values($names);
        $this->assertSame(
            array_search('MetaDescription', $names) - 1,
            array_search('MetaTitle', $names),
            'MetaTitle directly before MetaDescription'
        );
    }

    public function testRedirectorPagesGetNoSeoFields(): void
    {
        # A redirector page never renders: robots, canonical and sharing fields would do nothing there
        $page = RedirectorPage::create(['Title' => 'Moved']);
        $page->write();
        $counts = $this->countFields($page->getCMSFields());

        foreach (['MetaTitle', 'CanonicalURL', 'SEOFBdescription', 'OGImage', 'MetaRobotsNoIndex'] as $name) {
            $this->assertArrayNotHasKey($name, $counts, "$name should not be on a redirector page");
        }
    }

    public function testSiteConfigFieldsAppearOnce(): void
    {
        $counts = $this->countFields(SiteConfig::current_site_config()->getCMSFields());
        $this->assertSame(1, $counts['SeoDefaultImage'] ?? 0);
        $this->assertSame(1, $counts['SeoTitleSuffix'] ?? 0);
    }

    public function testLabelsAreTranslatable(): void
    {
        $labels = SeoTestPage::singleton()->fieldLabels();
        # Labels come from the module's lang files, not raw column names
        $this->assertNotSame('SEOFBdescription', $labels['SEOFBdescription'] ?? 'SEOFBdescription');
        $this->assertNotSame('MetaRobotsNoCache', $labels['MetaRobotsNoCache'] ?? 'MetaRobotsNoCache');
    }
}
