<?php

namespace Restruct\Silverstripe\SEO\Tests\Schema;

use Restruct\Silverstripe\SEO\Tests\Stub\Schema\SchemaTestJobPage;
use Restruct\Silverstripe\SEO\Tests\Stub\SeoTestPage;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Forms\LiteralField;
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

    private function preview(SeoTestPage $page): ?string
    {
        $toggle = $page->getCMSFields()->fieldByName('Root.Main.Metadata');
        $this->assertNotNull($toggle);
        $field = $toggle->getChildren()->fieldByName('SeoSchemaPreview');
        if (!$field) {
            return null;
        }
        $this->assertInstanceOf(LiteralField::class, $field);
        return (string) $field->getContent();
    }

    public function testPreviewShowsTheGraphEscaped(): void
    {
        $page = SeoTestPage::create(['Title' => 'Fish <b>&</b> chips', 'URLSegment' => 'fish']);
        $page->write();
        $html = $this->preview($page);

        $this->assertNotNull($html, 'the preview is in the Metadata toggle');
        $this->assertStringContainsString('<pre', $html);
        $this->assertStringContainsString('&quot;@type&quot;: &quot;WebPage&quot;', $html, 'pretty-printed and escaped');
        $this->assertStringNotContainsString('<b>', $html);
    }

    public function testPreviewListsWarnings(): void
    {
        $page = SchemaTestJobPage::create(['Title' => 'Welder', 'URLSegment' => 'welder', 'Content' => '<p>Join us.</p>']);
        $page->write();
        $html = $this->preview($page);

        $this->assertStringContainsString('JobPosting lacks datePosted, which is required: it is not output.', $html);
        $this->assertStringContainsString('JobPosting', $html);
    }

    public function testNoPreviewBeforeTheFirstSave(): void
    {
        $this->assertNull($this->preview(SeoTestPage::create(['Title' => 'New'])));
    }
}
