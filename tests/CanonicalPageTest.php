<?php

namespace Restruct\Silverstripe\SEO\Tests;

use Restruct\Silverstripe\SEO\Tests\Stub\SeoCanonicalHookTestExtension;
use Restruct\Silverstripe\SEO\Tests\Stub\SeoTestPage;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\CMS\Model\VirtualPage;
use SilverStripe\Control\Director;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Forms\Form;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\TreeDropdownField;
use SilverStripe\i18n\i18n;
use SilverStripe\ORM\DataObject;
use SilverStripe\Versioned\Versioned;

/**
 * A canonical page of this site, picked in the page tree (has_one CanonicalPage): it wins over the free-text
 * Canonical URL, which stays for an original on another site. The canonical is the picked page's absolute link
 * at render time, of its published version; a picked page that is not published (any more) or is gone is
 * passed over, and the page falls back to the canonical it would have without it (the canonical check then
 * says so, see CanonicalPageCheckTest).
 */
class CanonicalPageTest extends SapphireTest
{
    protected $usesDatabase = true;

    protected static $extra_dataobjects = [
        SeoTestPage::class,
    ];

    protected static $required_extensions = [
        SiteTree::class => [
            SeoCanonicalHookTestExtension::class,
        ],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        SeoCanonicalHookTestExtension::$suffix = null;
    }

    protected function tearDown(): void
    {
        SeoCanonicalHookTestExtension::$suffix = null;
        parent::tearDown();
    }

    private function page(array $data = [], bool $publish = false): SeoTestPage
    {
        static $n = 0;
        $n++;
        $page = SeoTestPage::create(array_merge(['Title' => 'Page ' . $n, 'URLSegment' => 'page-' . $n], $data));
        $page->write();
        if ($publish) {
            $page->publishSingle();
        }
        return $page;
    }

    public function testThePickedPageIsTheCanonical(): void
    {
        $original = $this->page(['URLSegment' => 'original'], true);
        $copy = $this->page(['CanonicalPageID' => $original->ID]);
        $this->assertSame(Director::absoluteURL('/original'), $copy->SeoCanonicalURL());
        $this->assertSame($original->ID, (int) $copy->CanonicalPage()->ID, 'a has_one to SiteTree');
    }

    public function testThePickedPageWinsOverTheFreeTextUrl(): void
    {
        $original = $this->page(['URLSegment' => 'original'], true);
        $copy = $this->page(['CanonicalPageID' => $original->ID, 'CanonicalURL' => 'https://other.example.org/x']);
        $this->assertSame(Director::absoluteURL('/original'), $copy->SeoCanonicalURL());
    }

    public function testTheLinkIsTheLiveOneAndFollowsAMove(): void
    {
        $original = $this->page(['URLSegment' => 'original'], true);
        $copy = $this->page(['CanonicalPageID' => $original->ID]);

        # Renamed in draft only: the published URL is still what visitors and crawlers reach
        $original->URLSegment = 'renamed';
        $original->write();
        $this->assertSame(Director::absoluteURL('/original'), $copy->SeoCanonicalURL());

        # Published: the canonical follows, as it is built when the page renders
        $original->publishSingle();
        $this->assertSame(Director::absoluteURL('/renamed'), $copy->SeoCanonicalURL());

        # No ?stage=Stage, also when read in draft
        Versioned::withVersionedMode(function () use ($copy) {
            Versioned::set_stage(Versioned::DRAFT);
            $this->assertStringNotContainsString('stage=', (string) $copy->SeoCanonicalURL());
        });
    }

    public function testAnUnpublishedPickedPageFallsBackToTheOwnCanonical(): void
    {
        $draftOnly = $this->page(['URLSegment' => 'draft-only']);
        $copy = $this->page(['URLSegment' => 'copy', 'CanonicalPageID' => $draftOnly->ID]);
        $this->assertSame(Director::absoluteURL('/copy'), $copy->SeoCanonicalURL());

        # The free-text URL is what the page has without the picked page
        $withUrl = $this->page(['CanonicalPageID' => $draftOnly->ID, 'CanonicalURL' => 'https://other.example.org/x']);
        $this->assertSame('https://other.example.org/x', $withUrl->SeoCanonicalURL());
    }

    public function testAnUnpublishedOrDeletedPickedPageFallsBackToo(): void
    {
        $original = $this->page(['URLSegment' => 'original'], true);
        $copy = $this->page(['URLSegment' => 'copy', 'CanonicalPageID' => $original->ID]);
        $original->doUnpublish();
        $this->assertSame(Director::absoluteURL('/copy'), $copy->SeoCanonicalURL());

        $other = $this->page(['URLSegment' => 'other'], true);
        $copy2 = $this->page(['URLSegment' => 'copy-2', 'CanonicalPageID' => $other->ID]);
        $other->doArchive();
        $this->assertSame(Director::absoluteURL('/copy-2'), $copy2->SeoCanonicalURL());
    }

    public function testPickingThePageItselfIsTheOwnCanonical(): void
    {
        $page = $this->page(['URLSegment' => 'self'], true);
        $page->CanonicalPageID = $page->ID;
        $page->write();
        $this->assertSame(Director::absoluteURL('/self'), $page->SeoCanonicalURL());
    }

    public function testTheHomepageAsPickedPageKeepsTheRootSlash(): void
    {
        $home = $this->page(['URLSegment' => 'home'], true);
        $copy = $this->page(['CanonicalPageID' => $home->ID]);
        $canonical = (string) $copy->SeoCanonicalURL();
        $this->assertSame(rtrim(Director::absoluteBaseURL(), '/') . '/', $canonical);
    }

    public function testTheProjectHookStillHasTheLastWord(): void
    {
        $original = $this->page(['URLSegment' => 'original'], true);
        $copy = $this->page(['CanonicalPageID' => $original->ID]);
        SeoCanonicalHookTestExtension::$suffix = '?via=hook';
        $this->assertSame(Director::absoluteURL('/original') . '?via=hook', $copy->SeoCanonicalURL());
    }

    public function testAVirtualPageTakesTheCanonicalPageOfItsSource(): void
    {
        $original = $this->page(['URLSegment' => 'original'], true);
        $source = $this->page(['URLSegment' => 'source', 'CanonicalPageID' => $original->ID], true);
        $virtual = VirtualPage::create(['Title' => 'Virtual', 'URLSegment' => 'virtual', 'CopyContentFromID' => $source->ID]);
        $virtual->write();
        $this->assertSame(Director::absoluteURL('/original'), $virtual->SeoCanonicalURL());

        # Copied from the source, so not editable on the virtual page
        $field = $virtual->getCMSFields()->dataFieldByName('CanonicalPageID');
        $this->assertNotNull($field);
        $this->assertTrue($field->isReadonly());
    }

    public function testATreeDropdownOnTheSeoTabBeforeTheCanonicalUrl(): void
    {
        $page = $this->page();
        $tab = $page->getCMSFields()->fieldByName('Root.SEO');
        $field = $tab->getChildren()->dataFieldByName('CanonicalPageID');
        $this->assertInstanceOf(TreeDropdownField::class, $field);
        $this->assertSame(SiteTree::class, $field->getSourceObject());
        $names = array_map(fn ($f) => $f->getName(), $tab->getChildren()->dataFields());
        $names = array_values($names);
        $this->assertSame(
            array_search('CanonicalPageID', $names, true) + 1,
            array_search('CanonicalURL', $names, true),
            'right before the free-text URL'
        );
        # The descriptions say which one is for what
        $this->assertStringContainsString('takes precedence', (string) $field->getDescription());
        $this->assertStringContainsString('another site', (string) $tab->getChildren()->dataFieldByName('CanonicalURL')->getDescription());
        # Appears once, not scaffolded a second time
        $this->assertCount(1, array_filter(
            $page->getCMSFields()->dataFields(),
            fn ($f) => in_array($f->getName(), ['CanonicalPageID', 'CanonicalPage'], true)
        ));
    }

    public function testThePageItselfCannotBePicked(): void
    {
        $page = $this->page();
        $field = $page->getCMSFields()->dataFieldByName('CanonicalPageID');
        $this->assertTrue($field->nodeIsDisabled($page), 'the page itself is disabled in the tree');
        $this->assertFalse($field->nodeIsDisabled($this->page()), 'another page is not');
    }

    public function testThePickedPageIsSavedFromTheForm(): void
    {
        $original = $this->page([], true);
        $page = $this->page();
        $form = Form::create(null, 'Form', $page->getCMSFields(), FieldList::create());
        $form->loadDataFrom(['CanonicalPageID' => $original->ID]);
        $form->saveInto($page);
        $page->write();
        $this->assertSame($original->ID, (int) SeoTestPage::get()->byID($page->ID)->CanonicalPageID);
    }

    public function testThePageClassCanHideOrLockIt(): void
    {
        SeoTestPage::config()->set('seo_hidden_fields', ['CanonicalPage']);
        $this->assertNull($this->page()->getCMSFields()->dataFieldByName('CanonicalPageID'));

        SeoTestPage::config()->set('seo_hidden_fields', []);
        SeoTestPage::config()->set('seo_readonly_fields', ['CanonicalPage']);
        $field = $this->page()->getCMSFields()->dataFieldByName('CanonicalPageID');
        $this->assertNotNull($field);
        $this->assertTrue($field->isReadonly());
    }

    public function testTheLabelsAreTranslated(): void
    {
        $page = $this->page();
        $this->assertSame('Canonical page', $page->fieldLabel('CanonicalPage'));
        # Core caches field labels per class, not per locale: cleared for the second language
        DataObject::reset();
        $this->assertSame('Canonieke pagina', i18n::with_locale('nl_NL', fn () => $page->fieldLabel('CanonicalPage')));
    }
}
