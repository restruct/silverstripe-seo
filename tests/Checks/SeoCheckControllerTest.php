<?php

namespace Restruct\Silverstripe\SEO\Tests\Checks;

use Restruct\Silverstripe\SEO\Checks\CheckRunner;
use Restruct\Silverstripe\SEO\Control\SeoCheckController;
use Restruct\Silverstripe\SEO\Model\SeoCheckIgnore;
use Restruct\Silverstripe\SEO\Model\SeoCheckResult;
use Restruct\Silverstripe\SEO\Tests\Stub\Checks\SeoCheckTestBlock;
use Restruct\Silverstripe\SEO\Tests\Stub\Checks\SeoCheckTestPage;
use Restruct\Silverstripe\SEO\Tests\Stub\Checks\SeoCheckSpyController;
use Restruct\Silverstripe\SEO\Tests\Stub\Checks\SeoDocumentedTestCheck;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\FunctionalTest;
use SilverStripe\Security\SecurityToken;
use SilverStripe\SiteConfig\SiteConfig;
use SilverStripe\View\SSViewer;

/**
 * The CMS endpoint behind the panel's "re-check" and "ignore" buttons: POST, CSRF-protected, editors only, and it
 * answers with results, never with the rendered page. Its "doc" action answers the "About this check" dialog
 * with the module's own spec of a registered check, rendered without any raw HTML.
 */
class SeoCheckControllerTest extends FunctionalTest
{
    protected $usesDatabase = true;

    protected static $extra_dataobjects = [
        SeoCheckTestPage::class,
        SeoCheckTestBlock::class,
    ];

    private bool $tokenWasEnabled;

    protected function setUp(): void
    {
        parent::setUp();
        # FunctionalTest::setUp() disabled the token; snapshot that, and restore what was there in tearDown
        $this->tokenWasEnabled = SecurityToken::is_enabled();
        SSViewer::config()->set('themes', ['restruct/silverstripe-seo:/tests/themes/seotest', '$default']);
        $config = SiteConfig::current_site_config();
        $config->Title = 'Example Site';
        $config->write();
    }

    protected function tearDown(): void
    {
        $this->tokenWasEnabled ? SecurityToken::enable() : SecurityToken::disable();
        parent::tearDown();
    }

    private function page(): SeoCheckTestPage
    {
        $page = SeoCheckTestPage::create([
            'Title' => 'Courses',
            'URLSegment' => 'courses',
            'HideH1' => true,
            'Content' => '<p>Short text.</p>',
        ]);
        $page->write();
        # In a block: the description fallback reads Content, and the results quote the description, so a
        # marker there would show up in the answer without the rendered page being in it
        SeoCheckTestBlock::create([
            'PageID' => $page->ID,
            'Title' => 'Block',
            'Content' => '<p>A marker sentence that only the rendered page contains.</p>',
        ])->write();
        return $page;
    }

    private function postAjax(string $action, array $data): HTTPResponse
    {
        return $this->post(
            SeoCheckController::singleton()->Link($action),
            $data,
            ['X-Requested-With' => 'XMLHttpRequest']
        );
    }

    public function testAnEditorGetsTheResults(): void
    {
        $page = $this->page();
        $this->logInWithPermission('ADMIN');
        $response = $this->postAjax('recheck', ['ID' => $page->ID]);

        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $this->assertStringContainsString('application/json', (string) $response->getHeader('Content-Type'));
        $data = json_decode((string) $response->getBody(), true);
        $this->assertSame($page->ID, $data['pageID']);
        $this->assertGreaterThanOrEqual(1, $data['counts']['warning']);
        $this->assertSame('fail', $data['results']['h1-missing']['status']);
        $this->assertStringContainsString('seo-checks', $data['panel']);
        $this->assertSame(1, SeoCheckResult::get()->filter('PageID', $page->ID)->count(), 'stored');

        # Results, never the rendered page itself
        $this->assertStringNotContainsString('A marker sentence', (string) $response->getBody());
        $this->assertStringNotContainsString('<html', (string) $response->getBody());
    }

    public function testGetIsRefused(): void
    {
        $page = $this->page();
        $this->logInWithPermission('ADMIN');
        $response = $this->get(SeoCheckController::singleton()->Link('recheck') . '?ID=' . $page->ID);
        $this->assertSame(405, $response->getStatusCode());
        $this->assertSame(0, SeoCheckResult::get()->filter('PageID', $page->ID)->count());
    }

    public function testAMissingCsrfTokenIsRefused(): void
    {
        $page = $this->page();
        $this->logInWithPermission('ADMIN');
        SecurityToken::enable();
        $response = $this->postAjax('recheck', ['ID' => $page->ID]);
        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame(0, SeoCheckResult::get()->filter('PageID', $page->ID)->count());

        # Control: the same request with the token is accepted
        $response = $this->postAjax('recheck', [
            'ID' => $page->ID,
            SecurityToken::inst()->getName() => SecurityToken::inst()->getValue(),
        ]);
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
    }

    public function testACmsUserWhoCannotEditThePageGets403(): void
    {
        $page = $this->page();
        $page->CanEditType = 'OnlyTheseUsers';
        $page->write();
        $this->logInWithPermission('CMS_ACCESS_CMSMain');
        $this->assertFalse($page->canEdit());

        $response = $this->postAjax('recheck', ['ID' => $page->ID]);
        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame(0, SeoCheckResult::get()->filter('PageID', $page->ID)->count());
    }

    public function testAVisitorWithoutCmsAccessGets403(): void
    {
        $page = $this->page();
        $response = $this->postAjax('recheck', ['ID' => $page->ID]);
        $this->assertSame(403, $response->getStatusCode());

        $this->logInWithPermission('SOME_OTHER_PERMISSION');
        $response = $this->postAjax('recheck', ['ID' => $page->ID]);
        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame(0, SeoCheckResult::get()->filter('PageID', $page->ID)->count());
    }

    public function testTheSessionLockIsReleasedOnlyAfterAuthorisation(): void
    {
        # The render can take seconds; holding the session lock that long blocks the editor's other CMS requests
        Injector::inst()->registerService(SeoCheckSpyController::create(), SeoCheckController::class);
        SeoCheckSpyController::$calls = [];
        $page = $this->page();

        $this->logInWithPermission('CMS_ACCESS_CMSMain');
        $page->CanEditType = 'OnlyTheseUsers';
        $page->write();
        $this->assertSame(403, $this->postAjax('recheck', ['ID' => $page->ID])->getStatusCode());
        $this->assertSame([], SeoCheckSpyController::$calls, 'not for a refused request');

        $this->logInWithPermission('ADMIN');
        $this->assertSame(200, $this->postAjax('recheck', ['ID' => $page->ID])->getStatusCode());
        $this->assertSame(['release'], SeoCheckSpyController::$calls);
    }

    public function testAnUnknownPageIs404(): void
    {
        $this->logInWithPermission('ADMIN');
        $this->assertSame(404, $this->postAjax('recheck', ['ID' => 987654])->getStatusCode());
        $this->assertSame(404, $this->postAjax('recheck', [])->getStatusCode());
    }

    public function testAnEditorCanIgnoreAndUnignoreACheck(): void
    {
        $page = $this->page();
        $member = $this->logInWithPermission('ADMIN');
        $response = $this->postAjax('ignore', ['ID' => $page->ID, 'Check' => 'h1-missing', 'Ignore' => 1]);
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $ignore = SeoCheckIgnore::get()->filter(['PageID' => $page->ID, 'CheckID' => 'h1-missing'])->first();
        $this->assertNotNull($ignore);
        $this->assertSame($member, (int) $ignore->MemberID);
        $data = json_decode((string) $response->getBody(), true);
        $this->assertTrue($data['results']['h1-missing']['ignored']);

        $this->postAjax('ignore', ['ID' => $page->ID, 'Check' => 'h1-missing', 'Ignore' => 0]);
        $this->assertSame(0, SeoCheckIgnore::get()->filter('PageID', $page->ID)->count());
    }

    public function testIgnoringAnUnknownCheckIsRefused(): void
    {
        $page = $this->page();
        $this->logInWithPermission('ADMIN');
        $response = $this->postAjax('ignore', ['ID' => $page->ID, 'Check' => 'no-such-check', 'Ignore' => 1]);
        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame(0, SeoCheckIgnore::get()->count());
    }

    public function testIgnoringNeedsEditRightsToo(): void
    {
        $page = $this->page();
        $page->CanEditType = 'OnlyTheseUsers';
        $page->write();
        $this->logInWithPermission('CMS_ACCESS_CMSMain');
        $response = $this->postAjax('ignore', ['ID' => $page->ID, 'Check' => 'h1-missing', 'Ignore' => 1]);
        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame(0, SeoCheckIgnore::get()->count());
    }

    private function getDoc($check, bool $ajax = true): HTTPResponse
    {
        $query = is_array($check) ? http_build_query(['check' => $check]) : 'check=' . rawurlencode((string) $check);
        return $this->get(
            SeoCheckController::singleton()->Link('doc') . '?' . $query,
            null,
            $ajax ? ['X-Requested-With' => 'XMLHttpRequest'] : []
        );
    }

    public function testTheDocOfARegisteredCheckIsRendered(): void
    {
        $this->logInWithPermission('ADMIN');
        $response = $this->getDoc('title-width');

        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $this->assertStringContainsString('application/json', (string) $response->getHeader('Content-Type'));
        $this->assertSame('nosniff', $response->getHeader('X-Content-Type-Options'));
        $data = json_decode((string) $response->getBody(), true);
        $this->assertSame('title-width', $data['check']);
        # The check's own title, as the panel names it: the spec's heading ("title-width: ...") is for developers
        $this->assertSame('Title fits in a search result', $data['title']);
        $this->assertStringContainsString('<h3>What it checks</h3>', $data['html']);
        $this->assertStringContainsString('<code>max_pixels</code>', $data['html']);
        $this->assertStringContainsString('<pre><code>', $data['html']);
        $this->assertStringContainsString(
            '<a href="https://developers.google.com/search/docs/appearance/title-link" target="_blank"',
            $data['html']
        );
    }

    public function testADocNeedsCmsAccessButNoPageRights(): void
    {
        # Not about a page: a CMS user who may edit nothing still reads what a check is for
        $this->logInWithPermission('CMS_ACCESS_CMSMain');
        $this->assertSame(200, $this->getDoc('h1-missing')->getStatusCode());
    }

    public function testADocWithoutCmsAccessIs403(): void
    {
        $this->assertSame(403, $this->getDoc('h1-missing')->getStatusCode(), 'logged out');
        $this->logInWithPermission('SOME_OTHER_PERMISSION');
        $response = $this->getDoc('h1-missing');
        $this->assertSame(403, $response->getStatusCode());
        $this->assertStringNotContainsString('What it checks', (string) $response->getBody());
        # Refused before the key is looked at: an unknown key is no different from a known one
        $this->assertSame(403, $this->getDoc('no-such-check')->getStatusCode());
    }

    public function testAnUnknownKeyIs404AndNeverReadAsAPath(): void
    {
        $this->logInWithPermission('ADMIN');
        foreach ([
            'no-such-check',
            '',
            # Not a registered check, whatever file it names
            '../../README',
            '../dropped-checks',
            'h1-missing.md',
            'H1-MISSING',
            'render',
            ['h1-missing'],
        ] as $check) {
            $response = $this->getDoc($check);
            $this->assertSame(404, $response->getStatusCode(), json_encode($check));
            $this->assertStringNotContainsString('What it checks', (string) $response->getBody());
        }
        $this->assertSame(404, $this->get(SeoCheckController::singleton()->Link('doc'))->getStatusCode(), 'no key');
    }

    public function testADisabledCheckHasNoDoc(): void
    {
        # Not registered once disabled, though its spec is still in docs/checks/
        CheckRunner::config()->merge('checks', ['h1-missing' => ['enabled' => false]]);
        $this->logInWithPermission('ADMIN');
        $this->assertSame(404, $this->getDoc('h1-missing')->getStatusCode());
        $this->assertSame(200, $this->getDoc('title-width')->getStatusCode(), 'control: an enabled one does');
    }

    public function testAProjectCheckHasNoDocHere(): void
    {
        # Its explanation is wherever its docsUrl() points; the module has no spec of it
        CheckRunner::config()->merge('checks', ['custom-documented' => ['class' => SeoDocumentedTestCheck::class]]);
        $this->logInWithPermission('ADMIN');
        $this->assertTrue(CheckRunner::singleton()->hasCheck('custom-documented'));
        $this->assertSame(404, $this->getDoc('custom-documented')->getStatusCode());
    }

    public function testNoRawHtmlOfTheSpecPassesThrough(): void
    {
        # The h1-missing spec mentions `<h1>` and `<main>`: they arrive as text
        $this->logInWithPermission('ADMIN');
        $data = json_decode((string) $this->getDoc('h1-missing')->getBody(), true);
        $this->assertStringContainsString('&lt;h1&gt;', $data['html']);
        $this->assertStringContainsString('&lt;main&gt;', $data['html']);
        $this->assertStringNotContainsString('<h1', $data['html']);
        $this->assertStringNotContainsString('<main', $data['html']);
    }
}
