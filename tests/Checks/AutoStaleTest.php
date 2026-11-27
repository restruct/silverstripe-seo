<?php

namespace Restruct\Silverstripe\SEO\Tests\Checks;

use Composer\InstalledVersions;
use Restruct\Silverstripe\SEO\Checks\CheckRunner;
use Restruct\Silverstripe\SEO\Forms\SeoChecksField;
use Restruct\Silverstripe\SEO\Model\SeoCheckResult;
use Restruct\Silverstripe\SEO\Report\SiteReportData;
use Restruct\Silverstripe\SEO\Tests\Stub\Checks\SeoVersionTestCheckRunner;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Security\Security;

/**
 * Results that ask to be checked again without anyone saving the page: one stored by another version of the
 * module (what a check finds may have changed with it), and one whose render failed (the cause may be gone, as
 * the homepage's 301 was after the renderer fix). The panel then re-checks when it opens.
 */
class AutoStaleTest extends ChecksTestCase
{
    public function testTheModuleVersionIsTheInstalledOne(): void
    {
        $runner = CheckRunner::singleton();
        $expected = InstalledVersions::isInstalled('restruct/silverstripe-seo')
            ? (string) InstalledVersions::getPrettyVersion('restruct/silverstripe-seo')
            : '';
        $this->assertSame($expected, $runner->moduleVersion());
    }

    public function testAResultStoredByAnotherModuleVersionIsStale(): void
    {
        Injector::inst()->registerService(new SeoVersionTestCheckRunner(), CheckRunner::class);
        SeoVersionTestCheckRunner::$version = '0.5.0';
        $page = $this->page();
        $result = CheckRunner::singleton()->checkPage($page, Security::getCurrentUser());
        $this->assertFalse($result->isStale(), 'fresh under the version that stored it');

        SeoVersionTestCheckRunner::$version = '0.5.1';
        $this->assertTrue(SeoCheckResult::forPage($page)->isStale(), 'stale once the module is updated');
    }

    public function testAResultWhoseRenderFailedIsStale(): void
    {
        $page = $this->page(['RedirectTo' => '/somewhere-else/']);
        $result = CheckRunner::singleton()->checkPage($page, Security::getCurrentUser());

        $this->assertTrue((bool) $result->RenderFailed, 'stored with the render-failed flag');
        $this->assertTrue($result->isStale(), 'checked again on the next open: the cause may be gone');
        $field = $page->getCMSFields()->flattenFields()->fieldByName('SeoChecks');
        $this->assertInstanceOf(SeoChecksField::class, $field);
        $this->assertStringContainsString('data-seo-stale="1"', (string) $field->FieldHolder());

        # The report reads the flag from its own column query, too
        $rows = [];
        foreach (SiteReportData::create(Security::getCurrentUser())->rows() as $row) {
            $rows[$row->PageID ?? $row->ID] = $row;
        }
        $statuses = array_map(fn ($row) => $row->Status, $rows);
        $this->assertContains(SiteReportData::STATUS_STALE, $statuses);
    }

    public function testAResultThatRenderedIsNotStale(): void
    {
        $page = $this->page();
        $result = CheckRunner::singleton()->checkPage($page, Security::getCurrentUser());

        $this->assertFalse((bool) $result->RenderFailed);
        $this->assertFalse($result->isStale());
    }
}
