<?php

namespace Restruct\Silverstripe\SEO\Tests\Schema;

use Restruct\Silverstripe\SEO\Tests\Stub\Schema\SchemaTestJobPage;
use Restruct\Silverstripe\SEO\Tests\Stub\SeoTestPage;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Forms\LiteralField;
use SilverStripe\i18n\i18n;
use SilverStripe\SiteConfig\SiteConfig;

/**
 * The page editor shows, read-only, the structured data the page would output and what the type registry says
 * is missing. Editors do not edit JSON-LD; this is for checking.
 */
class SchemaCmsPreviewTest extends SapphireTest
{
    protected $usesDatabase = true;

    protected static $extra_dataobjects = [
        SeoTestPage::class,
        SchemaTestJobPage::class,
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $config = SiteConfig::current_site_config();
        $config->Title = 'Example Site';
        $config->write();
    }

    protected function tearDown(): void
    {
        i18n::set_locale(i18n::config()->get('default_locale'));
        parent::tearDown();
    }

    private function preview(SeoTestPage $page): ?string
    {
        $tab = $page->getCMSFields()->fieldByName('Root.SEO');
        $this->assertNotNull($tab);
        $field = $tab->getChildren()->fieldByName('SeoSchemaPreview');
        if (!$field) {
            return null;
        }
        $this->assertInstanceOf(LiteralField::class, $field);
        return (string) $field->getContent();
    }

    public function testPreviewShowsTheGraphEscaped(): void
    {
        # JSON_HEX_TAG already takes care of < and >; " and & are left to the HTML escaping of the preview
        $page = SeoTestPage::create(['Title' => 'Fish "and" <b>&</b> chips', 'URLSegment' => 'fish']);
        $page->write();
        $html = $this->preview($page);

        $this->assertNotNull($html, 'the preview is on the SEO tab');
        $this->assertStringContainsString('<pre', $html);
        $this->assertStringContainsString('&quot;@type&quot;: &quot;WebPage&quot;', $html, 'pretty-printed and escaped');
        $this->assertStringNotContainsString('<b>', $html);
        # The title as JSON wrote it, then escaped for HTML: \" becomes \&quot; and & becomes &amp;
        $this->assertStringContainsString('Fish \\&quot;and\\&quot; \\u003Cb\\u003E&amp;\\u003C/b\\u003E chips', $html);
        $this->assertStringNotContainsString('\\"and\\"', $html);
        $this->assertStringNotContainsString('E&\\u003C', $html);
    }

    public function testPreviewListsWarnings(): void
    {
        $page = SchemaTestJobPage::create(['Title' => 'Welder', 'URLSegment' => 'welder', 'Content' => '<p>Join us.</p>']);
        $page->write();
        $html = $this->preview($page);

        $this->assertStringContainsString('JobPosting lacks datePosted, which is required: it is not output.', $html);
        $this->assertStringContainsString('JobPosting', $html);
    }

    public function testPreviewUsesTheSiteLocaleNotTheEditors(): void
    {
        i18n::config()->set('default_locale', 'en_US');
        $page = SeoTestPage::create(['Title' => 'About us', 'URLSegment' => 'about-us']);
        $page->write();
        # The CMS switches i18n to the editor's own locale
        i18n::set_locale('nl_NL');

        $html = $this->preview($page);
        $this->assertStringContainsString('&quot;inLanguage&quot;: &quot;en-US&quot;', $html);
        $this->assertStringNotContainsString('nl-NL', $html);
    }

    public function testNoPreviewBeforeTheFirstSave(): void
    {
        $this->assertNull($this->preview(SeoTestPage::create(['Title' => 'New'])));
    }
}
