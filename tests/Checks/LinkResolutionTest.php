<?php

namespace Restruct\Silverstripe\SEO\Tests\Checks;

use Restruct\Silverstripe\SEO\Checks\LinkResolver;
use Restruct\Silverstripe\SEO\Tests\SeoTestImages;
use Restruct\Silverstripe\SEO\Tests\Stub\Checks\SeoLocalePrefixTestExtension;
use SilverStripe\Assets\Dev\TestAssetStore;
use SilverStripe\Control\Director;

/**
 * Internal URLs that exist although they are not a page or a plain file name: image variants (resized,
 * converted to another format), static files outside the assets folder, the old URL of a renamed page; and the
 * hook a translation module can use for locale prefixes.
 */
class LinkResolutionTest extends ChecksTestCase
{
    use SeoTestImages;

    protected static $required_extensions = [
        LinkResolver::class => [SeoLocalePrefixTestExtension::class],
    ];

    private string $publicFile = '';

    protected function setUp(): void
    {
        parent::setUp();
        TestAssetStore::activate('SeoLinkResolutionTest');
        # A static file in the web root, as favicon.ico or robots.txt would be
        $this->publicFile = Director::publicFolder() . '/seo-link-resolution-test.txt';
        file_put_contents($this->publicFile, 'static');
    }

    protected function tearDown(): void
    {
        if (is_file($this->publicFile)) {
            unlink($this->publicFile);
        }
        TestAssetStore::reset();
        parent::tearDown();
    }

    private function withHtml(string $html): array
    {
        return ['Content' => static::GOOD_TEXT . '<p>' . $html . '</p>'];
    }

    public function testImageVariantsResolveToTheirFile(): void
    {
        $image = $this->createTestImage('photo.png', 400, 300);
        $scaled = $image->ScaleWidth(100);
        $converted = $image->Convert('webp');
        $this->assertNotNull($converted, 'GD can write webp here');
        $this->assertStringEndsWith('.webp', $converted->getURL(), 'the variant changes the extension');

        $page = $this->page($this->withHtml(
            '<img src="' . $scaled->getURL() . '" alt="A photo of the workshop">'
            . '<picture><source srcset="' . $converted->getURL() . ' 1x, ' . $scaled->getURL() . ' 2x" type="image/webp">'
            . '<img src="' . $image->getURL() . '" srcset="' . $scaled->getURL() . ' 100w" alt="The workshop from outside"></picture>'
        ));
        $this->assertCheckPasses($page, 'broken-links');
        $this->assertCheckPasses($page, 'unpublished-links');
    }

    public function testAMissingFileInASrcsetIsReported(): void
    {
        $image = $this->createTestImage('photo.png', 400, 300);
        $page = $this->page($this->withHtml(
            '<picture><source srcset="/assets/SeoLinkResolutionTest/Uploads/gone.webp 1x">'
            . '<img src="' . $image->getURL() . '" alt="The workshop from outside"></picture>'
        ));
        $this->assertCheckFails($page, 'broken-links', 'gone.webp');
    }

    public function testStaticFilesOutsideTheAssetsFolderAreNotPages(): void
    {
        $page = $this->page($this->withHtml(
            '<a href="/seo-link-resolution-test.txt">a static file</a> '
            # The admin's, which every CMS install exposes (a module symlinked from a path repository outside
            # the project is not exposed under its own name)
            . '<a href="/_resources/vendor/silverstripe/admin/client/dist/js/bundle.js">a resource</a>'
        ));
        $this->assertCheckPasses($page, 'broken-links');

        $missing = $this->page($this->withHtml('<a href="/_resources/vendor/nope/missing.js">a missing resource</a>'));
        $this->assertCheckFails($missing, 'broken-links', 'missing.js');
    }

    public function testAPathOutsideThePublicFolderIsNotAFile(): void
    {
        # ../ must not reach files outside the web root (the project's .env, say)
        $page = $this->page($this->withHtml('<a href="/../composer.json">escape</a>'));
        $this->assertCheckFails($page, 'broken-links', 'composer.json');
    }

    public function testTheOldUrlOfARenamedPageIsARedirectNotABrokenLink(): void
    {
        $target = $this->page(['URLSegment' => 'old-name'], [], true);
        $target->URLSegment = 'new-name';
        $target->write();
        $target->publishSingle();

        $page = $this->page($this->withHtml('<a href="/old-name">the old address</a>'));
        $this->assertCheckPasses($page, 'broken-links');
        $this->assertCheckFails($page, 'link-redirects', 'old-name');
    }

    public function testAnExtensionCanResolveLocalePrefixes(): void
    {
        $this->page(['URLSegment' => 'cursussen'], [], true);
        $page = $this->page($this->withHtml('<a href="/nl/cursussen">de cursussen</a>'));
        $this->assertCheckPasses($page, 'broken-links');
    }
}
