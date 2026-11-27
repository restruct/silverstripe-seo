<?php

namespace Restruct\Silverstripe\SEO\Tests;

use Restruct\Silverstripe\SEO\Tests\Stub\SeoFieldRulesTestPage;
use Restruct\Silverstripe\SEO\Tests\Stub\SeoTestPage;
use SilverStripe\CMS\Model\RedirectorPage;
use SilverStripe\CMS\Model\VirtualPage;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Forms\CompositeField;
use SilverStripe\Admin\LeftAndMain;
use SilverStripe\View\SSViewer;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\Form;
use SilverStripe\i18n\i18n;
use SilverStripe\ORM\DataObject;
use SilverStripe\SiteConfig\SiteConfig;

/**
 * SS6's SiteTree scaffolds every database field an extension adds, before updateCMSFields runs. A module that
 * then adds its own field for the same column gets "a field called X appears twice", or, if it guards against
 * that, the field shows up twice in two places. These tests pin that every SEO field appears exactly once, where
 * it is meant to be.
 */
class SeoCmsFieldsTest extends SapphireTest
{
    # Counts every occurrence of a field, also one present twice
    use SeoFieldCounting;

    protected $usesDatabase = true;

    protected static $extra_dataobjects = [
        SeoTestPage::class,
        SeoFieldRulesTestPage::class,
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

    public function testFieldsLiveInTheirOwnSeoTabAfterMain(): void
    {
        $page = SeoTestPage::create(['Title' => 'About us']);
        $page->write();
        $fields = $page->getCMSFields();

        $tab = $fields->fieldByName('Root.SEO');
        $this->assertNotNull($tab, 'a tab of their own, not a toggle below the content');
        $this->assertSame('SEO', $tab->Title());
        foreach (self::PAGE_FIELDS as $name) {
            $this->assertNotNull($tab->getChildren()->dataFieldByName($name), "$name should be on the SEO tab");
        }
        # Core's MetaDescription and ExtraMeta moved along, so core's toggle was left empty and is gone
        $this->assertNotNull($tab->getChildren()->dataFieldByName('ExtraMeta'), 'ExtraMeta should be on the SEO tab');
        $this->assertNull($fields->fieldByName('Root.Main.Metadata'), 'the empty Metadata toggle is removed');

        # Right after the Main tab
        $tabs = array_values(array_map(fn ($f) => $f->getName(), $fields->fieldByName('Root')->getChildren()->toArray()));
        $this->assertSame(array_search('Main', $tabs, true) + 1, array_search('SEO', $tabs, true), 'SEO comes right after Main');

        # Meta title goes right before the description, where editors look for it
        $names = array_map(fn ($f) => $f->getName(), $tab->getChildren()->getDataFields());
        $names = array_values($names);
        $this->assertSame(
            array_search('MetaDescription', $names) - 1,
            array_search('MetaTitle', $names),
            'MetaTitle directly before MetaDescription'
        );
    }

    public function testTheTabHoldsTheFieldsInOrder(): void
    {
        $this->logInWithPermission('ADMIN');
        $page = SeoTestPage::create(['Title' => 'About us']);
        $page->write();
        $tab = $page->getCMSFields()->fieldByName('Root.SEO');

        # The checks panel and the preview open the tab, then the fields
        $names = array_values(array_map(fn ($f) => $f->getName(), $tab->getChildren()->toArray()));
        $this->assertSame([
            'SeoChecks',
            'SeoSnippetPreview',
            'MetaTitle',
            'MetaDescription',
            'SeoRobots',
            'CanonicalURL',
            'SeoSharingHeader',
            'SEOFBdescription',
            'OGImage',
            'ExtraMeta',
            'SeoSchemaHeader',
            'SeoSchemaPreview',
        ], $names);
    }

    public function testTheTabIsConfigurable(): void
    {
        SeoTestPage::config()->set('seo_tab', 'Root.Search');
        $page = SeoTestPage::create(['Title' => 'About us']);
        $page->write();
        $fields = $page->getCMSFields();

        $this->assertNull($fields->fieldByName('Root.SEO'));
        $tab = $fields->fieldByName('Root.Search');
        $this->assertNotNull($tab);
        foreach (self::PAGE_FIELDS as $name) {
            $this->assertNotNull($tab->getChildren()->dataFieldByName($name), "$name should be on the configured tab");
        }
        $tabs = array_values(array_map(fn ($f) => $f->getName(), $fields->fieldByName('Root')->getChildren()->toArray()));
        $this->assertSame(array_search('Main', $tabs, true) + 1, array_search('Search', $tabs, true), 'after Main');
    }

    public function testTheRobotsCheckboxesFormOneLabelledGroup(): void
    {
        $page = SeoTestPage::create(['Title' => 'About us']);
        $page->write();
        $fields = $page->getCMSFields();

        $this->assertNull($fields->fieldByName('SeoRobotsHeader'), 'no separate heading above the checkboxes');
        $group = $fields->fieldByName('Root.SEO')->getChildren()->fieldByName('SeoRobots');
        $this->assertInstanceOf(CompositeField::class, $group);
        $this->assertSame('Search engine indexing', $group->Title());
        $children = array_map(fn ($f) => $f->getName(), $group->getChildren()->toArray());
        $this->assertSame(['MetaRobotsNoIndex', 'MetaRobotsNoFollow', 'MetaRobotsNoSnippet', 'MetaRobotsNoCache'], $children);

        # The group's title fills the label column and the checkboxes sit in the field column under it, each
        # with its description below its own label: no empty label column in front of a checkbox. Rendered with
        # the CMS's form templates, as in the page editor
        $themes = SSViewer::get_themes();
        SSViewer::set_themes(LeftAndMain::config()->get('admin_themes'));
        try {
            $html = (string) $group->FieldHolder();
        } finally {
            SSViewer::set_themes($themes);
        }
        $this->assertMatchesRegularExpression('#<label[^>]*class="[^"]*form__field-label[^"]*"[^>]*>\s*Search engine indexing\s*</label>#', $html);
        $this->assertStringNotContainsString('form__field-holder--no-label', $html);
        $this->assertMatchesRegularExpression('#MetaRobotsNoIndex.*?form-check-label.*?form__field-description#s', $html);
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

    public function testFieldsAreReadOnlyOnAVirtualPage(): void
    {
        # A virtual page copies these columns from its source on every write (getVirtualFields /
        # refreshFromCopied): an editable field would silently lose what the editor typed. Core makes its own
        # copied fields (MetaDescription among them) read-only the same way.
        $source = SeoTestPage::create(['Title' => 'Original']);
        $source->write();
        $virtual = VirtualPage::create(['Title' => 'Copy', 'CopyContentFromID' => $source->ID]);
        $virtual->write();

        $fields = $virtual->getCMSFields();
        $this->assertTrue($fields->dataFieldByName('MetaDescription')?->isReadonly(), 'core reference: read-only');
        foreach (array_diff(self::PAGE_FIELDS, ['MetaDescription']) as $name) {
            $field = $fields->dataFieldByName($name);
            $this->assertNotNull($field, "$name is shown on a virtual page");
            $this->assertTrue($field->isReadonly(), "$name should be read-only on a virtual page");
        }
    }

    public function testAPageClassHidesAndLocksFields(): void
    {
        $page = SeoFieldRulesTestPage::create(['Title' => 'A course']);
        $page->write();
        $fields = $page->getCMSFields();
        $counts = $this->countFields($fields);

        # Hidden: not in the form at all
        $this->assertArrayNotHasKey('MetaTitle', $counts);
        $this->assertArrayNotHasKey('OGImage', $counts);
        # Read-only: shown, once, but not editable
        foreach (['CanonicalURL', 'MetaRobotsNoIndex', 'MetaDescription'] as $name) {
            $this->assertSame(1, $counts[$name] ?? 0, "$name should appear exactly once");
            $this->assertTrue($fields->dataFieldByName($name)->isReadonly(), "$name should be read-only");
        }
        # The rest is untouched
        foreach (['MetaRobotsNoFollow', 'SEOFBdescription'] as $name) {
            $this->assertFalse($fields->dataFieldByName($name)->isReadonly(), "$name should stay editable");
        }
    }

    public function testOtherPageClassesKeepEveryField(): void
    {
        $this->assertSame([], SeoTestPage::config()->get('seo_hidden_fields'));
        $this->assertSame([], SeoTestPage::config()->get('seo_readonly_fields'));
        $page = SeoTestPage::create(['Title' => 'About us']);
        $page->write();
        $fields = $page->getCMSFields();
        $this->assertFalse($fields->dataFieldByName('MetaTitle')->isReadonly());
        $this->assertFalse($fields->dataFieldByName('CanonicalURL')->isReadonly());
    }

    public function testAHiddenFieldKeepsItsValueOnSave(): void
    {
        # Hiding a field only changes the form: a value already stored stays, and a save does not empty it
        $page = SeoFieldRulesTestPage::create(['Title' => 'A course', 'MetaTitle' => 'Stored']);
        $page->write();
        $form = Form::create(null, 'Form', $page->getCMSFields(), FieldList::create());
        $form->loadDataFrom($page);
        $form->saveInto($page);
        $page->write();
        $this->assertSame('Stored', SeoFieldRulesTestPage::get()->byID($page->ID)->getField('MetaTitle'));
    }

    public function testSiteConfigFieldsAppearOnce(): void
    {
        $counts = $this->countFields(SiteConfig::current_site_config()->getCMSFields());
        $this->assertSame(1, $counts['SeoDefaultImage'] ?? 0);
        $this->assertSame(1, $counts['SeoTitleSuffix'] ?? 0);
    }

    public function testOrganizationContactFieldsAreOnTheSeoTab(): void
    {
        $fields = SiteConfig::current_site_config()->getCMSFields();
        $counts = $this->countFields($fields);
        $tab = $fields->fieldByName('Root.SEO');
        $this->assertNotNull($tab);
        foreach ([
            'SeoOrgDescription', 'SeoOrgEmail', 'SeoOrgTelephone', 'SeoOrgStreet', 'SeoOrgPostalCode',
            'SeoOrgLocality', 'SeoOrgRegion', 'SeoOrgCountry',
        ] as $name) {
            $this->assertSame(1, $counts[$name] ?? 0, "$name should appear exactly once");
            $this->assertNotNull($tab->getChildren()->dataFieldByName($name), "$name should be on the SEO tab");
        }
    }

    public function testOrganizationContactLabelsAreTranslatable(): void
    {
        $english = SiteConfig::singleton()->fieldLabels();
        $this->assertSame('Telephone', $english['SeoOrgTelephone'] ?? null);
        $dutch = i18n::with_locale('nl_NL', function () {
            DataObject::reset();
            return SiteConfig::singleton()->fieldLabels();
        });
        DataObject::reset();
        $this->assertSame('Telefoonnummer', $dutch['SeoOrgTelephone'] ?? null);
        $this->assertSame('Plaats', $dutch['SeoOrgLocality'] ?? null);
    }

    public function testLabelsAreTranslatable(): void
    {
        $labels = SeoTestPage::singleton()->fieldLabels();
        # Labels come from the module's lang files, not raw column names
        $this->assertNotSame('SEOFBdescription', $labels['SEOFBdescription'] ?? 'SEOFBdescription');
        $this->assertNotSame('MetaRobotsNoCache', $labels['MetaRobotsNoCache'] ?? 'MetaRobotsNoCache');

        # ...and in another locale from that locale's file: proof the keys resolve through i18n, not only the
        # English defaults in the code. fieldLabels() caches per class, not per locale, hence the resets.
        $dutch = i18n::with_locale('nl_NL', function () {
            DataObject::reset();
            return SeoTestPage::singleton()->fieldLabels();
        });
        DataObject::reset();
        $this->assertSame('Deelbeschrijving', $dutch['SEOFBdescription'] ?? null);
        $this->assertSame('Geen kopie in de cache van zoekmachines (noarchive)', $dutch['MetaRobotsNoCache'] ?? null);
        $this->assertSame('Metatitel', $dutch['MetaTitle'] ?? null);
    }
}
