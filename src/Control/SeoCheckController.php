<?php

namespace Restruct\Silverstripe\SEO\Control;

use Restruct\Silverstripe\SEO\Checks\CheckRunner;
use Restruct\Silverstripe\SEO\Forms\SeoChecksField;
use Restruct\Silverstripe\SEO\Model\SeoCheckIgnore;
use Restruct\Silverstripe\SEO\Model\SeoCheckResult;
use Restruct\Silverstripe\SEO\Text\DocMarkdown;
use SilverStripe\Admin\AdminController;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Core\Convert;
use SilverStripe\Model\ArrayData;
use SilverStripe\ORM\FieldType\DBField;
use SilverStripe\Security\Permission;
use SilverStripe\Security\Security;
use SilverStripe\Security\SecurityToken;
use SilverStripe\Versioned\Versioned;

/**
 * The CMS endpoint behind the SEO panel: `admin/seo-checks/recheck` runs the checks on a page's draft and stores
 * the outcome, `admin/seo-checks/ignore` ignores or un-ignores one check for a page.
 *
 * Both are POST only (405 otherwise) and need the CMS's CSRF token (400 without it). The CMS access check is
 * AdminController's (CMS_ACCESS_CMSMain); on top of that the member must be able to edit the page (403). The page
 * ID comes from the edit form's own ID field, posted by the panel; nothing else of the request is used to pick
 * what is rendered. The checks render the draft as the member asking, and the answer holds the results and the
 * panel's own markup, never the rendered page.
 *
 * `admin/seo-checks/doc?check=<id>` answers the panel's "About this check" dialog with the module's own spec of
 * that check, rendered from docs/checks/<id>.md without any raw HTML ({@see DocMarkdown}). It only reads, so it
 * takes any method and no token; it needs CMS access (403), no rights on a page. The answer starts with the
 * check's explanation and how-to-fix advice for editors (translated); the spec itself, written for developers,
 * is added for ADMIN members only. The id must be a registered
 * module check ({@see CheckRunner::docFile()}), anything else is a 404; it is never used as a path.
 */
class SeoCheckController extends AdminController
{
    private static ?string $url_segment = 'seo-checks';

    private static string|array $required_permission_codes = [
        'CMS_ACCESS_CMSMain',
    ];

    private static $allowed_actions = [
        'recheck',
        'ignore',
        'doc',
    ];

    public function recheck(HTTPRequest $request): HTTPResponse
    {
        $page = $this->editablePage($request);
        CheckRunner::singleton()->checkPage($page, Security::getCurrentUser());
        return $this->answer($page);
    }

    public function ignore(HTTPRequest $request): HTTPResponse
    {
        $page = $this->editablePage($request);
        $check = (string) $request->postVar('Check');
        if (!CheckRunner::singleton()->hasCheck($check)) {
            $this->jsonError(400, _t(__CLASS__ . '.UnknownCheck', 'Unknown check.'));
        }
        if ($request->postVar('Ignore')) {
            SeoCheckIgnore::ignore($page, $check, Security::getCurrentUser());
        } else {
            SeoCheckIgnore::unignore($page, $check);
        }
        # The panel shows results; when there are none stored yet, check now so the ignore is visible in them
        if (!SeoCheckResult::forPage($page)) {
            CheckRunner::singleton()->checkPage($page, Security::getCurrentUser());
        }
        return $this->answer($page);
    }

    public function doc(HTTPRequest $request): HTTPResponse
    {
        # As in editablePage(): init() lets an ajax request of a member without CMS access run
        if (!$this->canView()) {
            $this->jsonError(403);
        }
        $check = $request->getVar('check');
        $file = is_string($check) ? CheckRunner::singleton()->docFile($check) : null;
        if ($file === null) {
            $this->jsonError(404, _t(__CLASS__ . '.UnknownCheck', 'Unknown check.'));
        }
        $this->releaseSessionLock();
        $runner = CheckRunner::singleton();
        $instance = $runner->getChecks()[$check];
        # The spec is for developers (class names, config, thresholds): only admins get it, the others not at all
        $spec = Permission::check('ADMIN')
            ? DocMarkdown::create()->render((string) file_get_contents($file))
            : null;
        $html = ArrayData::create([
            # Plain strings, escaped by the template
            'About' => $runner->about($check),
            # The check's own general advice, the one the panel shows under "How to fix"
            'HowToFix' => $instance->howToFix(),
            # Escaped by the renderer, with only its own tags
            'Spec' => $spec ? DBField::create_field('HTMLFragment', $spec['html']) : null,
        ])->renderWith('Restruct\\Silverstripe\\SEO\\Control\\SeoCheckDoc');
        $this->getResponse()->addHeader('X-Content-Type-Options', 'nosniff');
        return $this->jsonSuccess(200, [
            'check' => $check,
            # The check's own title in the reader's language, escaped: the script sets it as markup
            'title' => Convert::raw2xml($instance->title()),
            'html' => trim((string) $html),
        ]);
    }

    /**
     * The page the request is about, after every guard: POST, CSRF token, CMS access, the page exists, and the
     * member may edit it. Each failure ends the request with its own status code. Once all pass, the session
     * lock is released.
     */
    protected function editablePage(HTTPRequest $request): SiteTree
    {
        if (!$request->isPOST()) {
            $this->jsonError(405, _t(__CLASS__ . '.PostOnly', 'Only POST requests are accepted.'));
        }
        if (!SecurityToken::inst()->checkRequest($request)) {
            $this->jsonError(400, _t(__CLASS__ . '.BadToken', 'The security token is missing or has expired. Reload the page and try again.'));
        }
        # AdminController::init() refuses members without CMS access, but for an ajax request it only sets a 403
        # on the response and lets the action run; refuse here as well
        if (!$this->canView()) {
            $this->jsonError(403);
        }
        $id = (int) $request->postVar('ID');
        $page = $id ? Versioned::get_by_stage(SiteTree::class, Versioned::DRAFT)->byID($id) : null;
        if (!$page instanceof SiteTree) {
            $this->jsonError(404, _t(__CLASS__ . '.NoPage', 'The page does not exist.'));
        }
        if (!$page->canEdit()) {
            $this->jsonError(403);
        }
        $this->releaseSessionLock();
        return $page;
    }

    /**
     * Let go of the PHP session lock once the request is authorised: a render can take seconds, and with the
     * file session handler every other request of the editor (the CMS polls) waits for the lock meanwhile.
     *
     * Nothing this endpoint does needs the session afterwards (the render runs with a fresh session of its
     * own, see PageRenderer). Note for anyone extending this endpoint: a session change made after this point
     * is lost. Core's Session::save() at the end of the request only restarts a session it thinks was never
     * started (isStarted() is a flag that stays true), so it applies the change to $_SESSION of a closed
     * session.
     */
    protected function releaseSessionLock(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
    }

    protected function answer(SiteTree $page): HTTPResponse
    {
        $result = SeoCheckResult::forPage($page);
        $entries = $result ? CheckRunner::singleton()->renderEntries($result->getEntries()) : [];
        $this->getResponse()->addHeader('X-Content-Type-Options', 'nosniff');
        return $this->jsonSuccess(200, [
            'pageID' => (int) $page->ID,
            'checkedAt' => $result?->CheckedAt,
            'counts' => SeoCheckResult::countEntries($entries),
            'results' => $entries,
            # The panel's own markup (escaped results), so the browser does not rebuild it
            'panel' => (string) SeoChecksField::create('SeoChecks', '', $page)->Field(),
        ]);
    }
}
