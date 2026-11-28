<?php

namespace Restruct\Silverstripe\SEO\Tests\Schema;

use Restruct\Silverstripe\SEO\Tests\Stub\Schema\SchemaTestJobPage;
use Restruct\Silverstripe\SEO\Tests\Stub\SeoTestPage;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\i18n\i18n;
use SilverStripe\ORM\DataObject;
use SilverStripe\SiteConfig\SiteConfig;

/**
 * The structured data section on the SEO tab, for editors (product owner, 2026-11-28): a plain-language summary
 * of what search engines get from the page, and each recommended gap a CMS field can fill as an action naming
 * that field and where it is. Gaps no field can fill are for developers only. The raw JSON-LD and the full
 * list of findings are folded under "For developers", rendered for ADMIN members only (as the check dialog).
 */
class SchemaEditorSummaryTest extends SapphireTest
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

    private function preview(SeoTestPage $page): string
    {
        $field = $page->getCMSFields()->fieldByName('Root.SEO')?->getChildren()->fieldByName('SeoSchemaPreview');
        $this->assertNotNull($field, 'the structured data section is on the SEO tab');
        return (string) $field->getContent();
    }

    /**
     * The section without the developers' fold.
     */
    private function editorPart(string $html): string
    {
        return (string) preg_replace('#<details class="seo-schema__developer">.*?</details>#s', '', $html);
    }

    private function aboutPage(): SeoTestPage
    {
        $page = SeoTestPage::create(['Title' => 'About us', 'URLSegment' => 'about-us']);
        $page->write();
        return $page;
    }

    public function testEditorsGetAPlainSummaryOfWhatSearchEnginesGet(): void
    {
        $this->logInWithPermission('CMS_ACCESS_CMSMain');
        $html = $this->editorPart($this->preview($this->aboutPage()));
        foreach (['Website', 'Organisation', 'Web page'] as $label) {
            $this->assertMatchesRegularExpression('#<strong>' . $label . '</strong>#', $html, $label);
        }
        # What it is good for, in plain words
        $this->assertStringContainsString('site name', $html);
        # No schema.org jargon in the editors' part
        $this->assertStringNotContainsString('@type', $html);
        $this->assertStringNotContainsString('which is recommended', $html);
    }

    public function testARecommendedGapIsAnActionNamingTheFieldAndWhereItIs(): void
    {
        $this->logInWithPermission('CMS_ACCESS_CMSMain');
        $html = $this->editorPart($this->preview($this->aboutPage()));
        $this->assertStringContainsString('Organisation: fill in Telephone under Settings &gt; SEO.', $html);
        $this->assertStringContainsString('Organisation: fill in Logo under Settings &gt; SEO.', $html);
        $this->assertStringNotContainsString('Organization lacks telephone', $html);
    }

    public function testAFilledFieldIsNoLongerAnAction(): void
    {
        $config = SiteConfig::current_site_config();
        $config->SeoOrgTelephone = '+31 20 123 4567';
        $config->write();
        $this->logInWithPermission('CMS_ACCESS_CMSMain');
        $html = $this->editorPart($this->preview($this->aboutPage()));
        $this->assertStringNotContainsString('fill in Telephone', $html);
        $this->assertStringContainsString('fill in Logo', $html, 'control: other gaps are still listed');
    }

    public function testGapsNoFieldCanFillAreLeftToDevelopers(): void
    {
        # legalName and contactPoint (Organization) and alternateName (WebSite) have no field in the CMS
        $this->logInWithPermission('ADMIN');
        $html = $this->preview($this->aboutPage());
        $editor = $this->editorPart($html);
        foreach (['legalName', 'contactPoint', 'alternateName'] as $property) {
            $this->assertStringNotContainsString($property, $editor, $property);
            $this->assertStringContainsString($property, $html, "$property is in the developers' part");
        }
    }

    public function testAGapInAFieldOfThePageNamesThatFieldOnThisPage(): void
    {
        $this->logInWithPermission('CMS_ACCESS_CMSMain');
        $page = SchemaTestJobPage::create([
            'Title' => 'Welder',
            'URLSegment' => 'welder',
            'Content' => '<p>Join us.</p>',
            'DatePosted' => '2026-11-01',
            'City' => 'Utrecht',
        ]);
        $page->write();
        $html = $this->editorPart($this->preview($page));
        $this->assertMatchesRegularExpression('#<strong>Job posting</strong>#', $html);
        $this->assertMatchesRegularExpression('#Job posting: fill in Valid through under [^<]+ on this page\.#', $html);
        $this->assertMatchesRegularExpression('#Job posting: fill in Employment type under [^<]+ on this page\.#', $html);
        # baseSalary is recommended, but no field of the page holds it
        $this->assertStringNotContainsString('baseSalary', $html);
    }

    public function testARequiredGapIsStillShownToEditors(): void
    {
        $this->logInWithPermission('CMS_ACCESS_CMSMain');
        $page = SchemaTestJobPage::create(['Title' => 'Welder', 'URLSegment' => 'welder', 'Content' => '<p>Join us.</p>']);
        $page->write();
        $html = $this->editorPart($this->preview($page));
        $this->assertStringContainsString('JobPosting lacks datePosted, which is required: it is not output.', $html);
    }

    public function testNonAdminsGetNoJsonAtAll(): void
    {
        $this->logInWithPermission('CMS_ACCESS_CMSMain');
        $html = $this->preview($this->aboutPage());
        $this->assertStringNotContainsString('<pre', $html);
        $this->assertStringNotContainsString('&quot;@context&quot;', $html);
        $this->assertStringNotContainsString('&quot;@type&quot;', $html);
        $this->assertStringNotContainsString('seo-schema__developer', $html);
        $this->assertStringNotContainsString('For developers', $html);
    }

    public function testAdminsGetTheJsonFoldedUnderForDevelopers(): void
    {
        $this->logInWithPermission('ADMIN');
        $html = $this->preview($this->aboutPage());
        $this->assertSame(
            1,
            preg_match('#<details class="seo-schema__developer">\s*<summary>For developers</summary>(.*?)</details>#s', $html, $m),
            'a closed fold'
        );
        $this->assertStringContainsString('<pre', $m[1]);
        $this->assertStringContainsString('&quot;@type&quot;: &quot;WebPage&quot;', $m[1]);
        # The registry's findings as they are, for whoever maps the data
        $this->assertStringContainsString('Organization lacks legalName, which is recommended.', $m[1]);
        $this->assertStringNotContainsString('<pre', $this->editorPart($html), 'the JSON only in the fold');
    }

    public function testTheSectionIsTranslated(): void
    {
        $this->logInWithPermission('ADMIN');
        $page = $this->aboutPage();
        # Core caches field labels per class, not per locale
        DataObject::reset();
        $html = i18n::with_locale('nl_NL', fn () => $this->preview($page));
        $this->assertStringContainsString('<summary>Voor ontwikkelaars</summary>', $html);
        $this->assertMatchesRegularExpression('#<strong>Organisatie</strong>#', $html);
        $this->assertStringContainsString('Organisatie: vul Telefoonnummer in onder Instellingen &gt; SEO.', $html);
    }
}
