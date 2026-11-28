<?php

namespace Restruct\Silverstripe\SEO\Tests\Checks;

use Restruct\Silverstripe\SEO\Checks\CheckRunner;
use Restruct\Silverstripe\SEO\Forms\SeoChecksField;
use Restruct\Silverstripe\SEO\Tests\SeoTestImages;
use SilverStripe\Assets\Dev\TestAssetStore;
use SilverStripe\Assets\Image;
use SilverStripe\Core\Convert;
use SilverStripe\ORM\DB;
use SilverStripe\Security\Security;

/**
 * An image in a check's details (the alt text checks) is named by its file, not by the URL of the variant the
 * page shows (photo__FitWzIwMCw2MF0_ExtRewriteWyJwbmciLCJ3ZWJwIl0.webp): the variant is parsed back to the
 * original by core's file ID helpers, and the File record found shows its title, linked to its edit form in the
 * Files admin. A URL that is no file of this site (another host, an unknown path) is shown as rendered.
 */
class ImageReferenceTest extends ChecksTestCase
{
    use SeoTestImages;

    protected function setUp(): void
    {
        parent::setUp();
        TestAssetStore::activate('SeoImageReferenceTest');
    }

    protected function tearDown(): void
    {
        TestAssetStore::reset();
        parent::tearDown();
    }

    private function image(string $title = 'Workshop photo'): Image
    {
        $image = $this->createTestImage('photo.png', 400, 300);
        $image->Title = $title;
        $image->write();
        $image->publishSingle();
        return $image;
    }

    private function withImages(string $images): array
    {
        return ['Content' => static::GOOD_TEXT . '<p>' . $images . '</p>'];
    }

    private function panelHtml($page): string
    {
        CheckRunner::singleton()->checkPage($page, Security::getCurrentUser());
        $field = $page->getCMSFields()->flattenFields()->fieldByName('SeoChecks');
        $this->assertInstanceOf(SeoChecksField::class, $field);
        return (string) $field->FieldHolder();
    }

    /**
     * The details list of one finding.
     */
    private function details(string $html, string $id): string
    {
        $this->assertSame(
            1,
            preg_match('#<li[^>]*data-check="' . preg_quote($id, '#') . '".*?<ul class="seo-checks__details">(.*?)</ul>#s', $html, $m),
            "details of $id"
        );
        return $m[1];
    }

    public function testAResizedAndConvertedVariantIsNamedByItsFileAndLinkedToTheFilesAdmin(): void
    {
        $image = $this->image();
        $variant = $image->Fit(200, 60)->Convert('webp');
        $this->assertNotNull($variant, 'GD can write webp here');
        # The URL the page shows: a variant of a variant, and the extension changed (ExtRewrite)
        $this->assertStringContainsString('__Fit', $variant->getURL());
        $this->assertStringContainsString('ExtRewrite', $variant->getURL());
        $this->assertStringEndsWith('.webp', $variant->getURL());

        $page = $this->page($this->withImages('<img src="' . $variant->getURL() . '">'));
        $entry = $this->assertCheckFails($page, 'image-alt-missing');
        $this->assertSame(['Workshop photo'], $entry['details'], 'the plain text names the file');

        $details = $this->details($this->panelHtml($page), 'image-alt-missing');
        $this->assertStringNotContainsString('__Fit', $details);
        $this->assertStringNotContainsString('.webp', $details);
        $this->assertSame(1, preg_match('#<a\b([^>]*)>\s*Workshop photo\s*</a>#', $details, $link), $details);
        $this->assertStringContainsString(
            'href="' . Convert::raw2att($image->getCMSEditLink()) . '"',
            $link[1]
        );
        $this->assertMatchesRegularExpression('#href="[^"]*admin/assets/show/\d+/edit/' . $image->ID . '"#', $link[1]);
        $this->assertStringContainsString('target="_blank"', $link[1]);
        $this->assertStringContainsString('rel="noopener noreferrer"', $link[1]);
    }

    public function testAConvertedOriginalIsFoundUnderItsOwnExtension(): void
    {
        # ExtRewrite alone: photo__ExtRewrite...webp is photo.png on the File record
        $image = $this->image('Converted original');
        $converted = $image->Convert('webp');
        $this->assertNotNull($converted, 'GD can write webp here');
        $this->assertStringContainsString('ExtRewrite', $converted->getURL());

        $page = $this->page($this->withImages('<img src="' . $converted->getURL() . '">'));
        $details = $this->details($this->panelHtml($page), 'image-alt-missing');
        $this->assertMatchesRegularExpression('#<a\b[^>]*/edit/' . $image->ID . '"[^>]*>\s*Converted original\s*</a>#', $details);
    }

    public function testTheAltQualityCheckNamesTheFileToo(): void
    {
        $image = $this->image();
        $variant = $image->ScaleWidth(100);
        $page = $this->page($this->withImages('<img src="' . $variant->getURL() . '" alt="Bowl">'));
        $entry = $this->assertCheckFails($page, 'image-alt-quality');
        $this->assertSame(['Workshop photo: "Bowl" (very short)'], $entry['details']);

        $details = $this->details($this->panelHtml($page), 'image-alt-quality');
        $this->assertMatchesRegularExpression('#<a\b[^>]*/edit/' . $image->ID . '"[^>]*>\s*Workshop photo\s*</a>: &quot;Bowl&quot; \(very short\)#', $details);
    }

    public function testAnUnknownPathIsShownAsRendered(): void
    {
        $src = '/assets/SeoImageReferenceTest/Uploads/gone__FitWzIwMCw2MF0.png';
        $page = $this->page($this->withImages('<img src="' . $src . '">'));
        $entry = $this->assertCheckFails($page, 'image-alt-missing');
        $this->assertSame([$src], $entry['details']);

        $details = $this->details($this->panelHtml($page), 'image-alt-missing');
        $this->assertStringContainsString($src, $details);
        $this->assertStringNotContainsString('<a', $details);
    }

    public function testAnExternalImageIsShownAsRendered(): void
    {
        # Same file name as a file of this site, on another host: not that file
        $this->image();
        $src = 'https://cdn.example.test/assets/seo-tests/photo__FitWzIwMCw2MF0.png';
        $page = $this->page($this->withImages('<img src="' . $src . '">'));
        $entry = $this->assertCheckFails($page, 'image-alt-missing');
        $this->assertSame([$src], $entry['details']);
        $this->assertStringNotContainsString('<a', $this->details($this->panelHtml($page), 'image-alt-missing'));
    }

    public function testTheFileTitleIsEscaped(): void
    {
        $image = $this->image('Bowl <b>new</b> & shiny');
        $page = $this->page($this->withImages('<img src="' . $image->getURL() . '">'));
        $details = $this->details($this->panelHtml($page), 'image-alt-missing');
        $this->assertStringNotContainsString('<b>', $details);
        $this->assertStringContainsString('Bowl &lt;b&gt;new&lt;/b&gt; &amp; shiny', $details);
    }

    public function testAFileWithoutATitleIsNamedByItsFileName(): void
    {
        $image = $this->image();
        # Title is filled from the name on write, so it is emptied in the tables; a record without one still has
        # a name
        foreach (['File', 'File_Live'] as $table) {
            DB::prepared_query("UPDATE \"$table\" SET \"Title\" = '' WHERE \"ID\" = ?", [$image->ID]);
        }
        $page = $this->page($this->withImages('<img src="' . $image->getURL() . '">'));
        $entry = $this->assertCheckFails($page, 'image-alt-missing');
        $this->assertSame([$image->Name], $entry['details']);
    }
}
