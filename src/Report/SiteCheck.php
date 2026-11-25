<?php

namespace Restruct\Silverstripe\SEO\Report;

use Psr\Log\LoggerInterface;
use Restruct\Silverstripe\PageRender\PageEligibility;
use Restruct\Silverstripe\SEO\Checks\CheckRunner;
use Restruct\Silverstripe\SEO\Model\SeoCheckResult;
use RuntimeException;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Security\Member;
use SilverStripe\Versioned\Versioned;
use Throwable;

/**
 * Checks pages for the site report outside the page editor: shared by {@see \Restruct\Silverstripe\SEO\Jobs\SeoSiteCheckJob}
 * and `sake tasks:seo-check-site`. Results are stored as the panel stores them (the draft row per page).
 *
 * Who the render runs as: by default nobody, a visitor, with the draft readable for the duration of the check
 * (Versioned's draft security is lifted and put back afterwards). Not the member who queued the job or started
 * the task: the queue runs a job as whoever queued it, and a render as an editor would store what that editor
 * sees (content for their groups only, CMS-only markup) in a result every other editor reads. As a visitor the
 * draft renders as the public site will show it once published. The consequence: a page visitors may not see
 * (CanViewType logged-in users or groups) cannot be rendered this way; it is left unchecked (counted as "not
 * visible") instead of being stored as a failed render. A site whose pages sit behind a login sets `run_as` to
 * the email of a dedicated member with view (and draft) rights; the results then reflect what that member sees.
 *
 * Side effects: every check renders a page through its own controller and templates (see
 * {@see \Restruct\Silverstripe\PageRender\PageRenderer}), so a site run renders every eligible page once. Page
 * code that counts views, sends mail, calls outside services or writes records does so for each, unless it
 * checks PageRenderer::isRendering().
 */
class SiteCheck
{
    use Configurable;
    use Injectable;

    public const CHECKED = 'checked';

    public const FRESH = 'fresh';

    public const NOT_VISIBLE = 'not-visible';

    public const MISSING = 'missing';

    public const FAILED = 'failed';

    /**
     * Email of the member to render as; empty renders as a visitor (with the draft readable).
     */
    private static string $run_as = '';

    /**
     * IDs of the pages the site checks cover: the draft pages {@see PageEligibility} allows for 'seo', by ID.
     *
     * @return int[]
     */
    public function pageIDs(): array
    {
        $ids = [];
        $eligibility = PageEligibility::singleton();
        foreach (Versioned::get_by_stage(SiteTree::class, Versioned::DRAFT)->sort('ID') as $page) {
            if ($eligibility->isEligible($page, 'seo')) {
                $ids[] = (int) $page->ID;
            }
        }
        return $ids;
    }

    /**
     * The member to render as, or null for a visitor.
     *
     * @throws RuntimeException When `run_as` names a member that does not exist: a run as a visitor instead
     *                          would store results the site did not ask for
     */
    public function runAs(): ?Member
    {
        $email = trim((string) static::config()->get('run_as'));
        if ($email === '') {
            return null;
        }
        $member = Member::get()->filter('Email', $email)->first();
        if (!$member) {
            throw new RuntimeException(sprintf('[seo] SiteCheck.run_as names no member (%s)', $email));
        }
        return $member;
    }

    /**
     * Check one page and store the result, unless it is fresh and only stale results are asked for.
     *
     * @return string One of the outcome constants
     */
    public function checkPage(int $pageID, bool $onlyStale, ?Member $member): string
    {
        try {
            return Member::actAs($member, fn () => $this->withDraftAccess($member, function () use ($pageID, $onlyStale, $member) {
                $page = Versioned::get_by_stage(SiteTree::class, Versioned::DRAFT)->byID($pageID);
                if (!$page instanceof SiteTree) {
                    return self::MISSING;
                }
                if ($onlyStale) {
                    $result = SeoCheckResult::forPage($page);
                    if ($result && !$result->isStale()) {
                        return self::FRESH;
                    }
                }
                $visible = Versioned::withVersionedMode(function () use ($page, $member) {
                    Versioned::set_stage(Versioned::DRAFT);
                    return $page->canView($member);
                });
                if (!$visible) {
                    return self::NOT_VISIBLE;
                }
                CheckRunner::singleton()->checkPage($page, $member);
                return self::CHECKED;
            }));
        } catch (Throwable $e) {
            # One page that breaks the checks costs that page, not the rest of the site run
            Injector::inst()->get(LoggerInterface::class)->warning(sprintf(
                '[seo] Site check of page #%d failed: %s',
                $pageID,
                $e->getMessage()
            ), ['exception' => $e]);
            return self::FAILED;
        }
    }

    /**
     * Run $fn with drafts readable by a visitor when rendering as nobody; put the setting back afterwards.
     */
    protected function withDraftAccess(?Member $member, callable $fn): mixed
    {
        if ($member) {
            return $fn();
        }
        $secured = Versioned::get_draft_site_secured();
        Versioned::set_draft_site_secured(false);
        try {
            return $fn();
        } finally {
            Versioned::set_draft_site_secured($secured);
        }
    }

    /**
     * An empty tally of outcomes.
     *
     * @return array<string, int>
     */
    public static function emptyCounts(): array
    {
        return [self::CHECKED => 0, self::FRESH => 0, self::NOT_VISIBLE => 0, self::MISSING => 0, self::FAILED => 0];
    }

    /**
     * The tally as one line: "3 checked, 1 up to date, 0 not visible, 0 gone, 0 failed".
     *
     * @param array<string, int> $counts
     */
    public static function describe(array $counts): string
    {
        return _t(__CLASS__ . '.Summary', '{checked} checked, {fresh} up to date, {hidden} not visible, {missing} gone, {failed} failed', [
            'checked' => (int) ($counts[self::CHECKED] ?? 0),
            'fresh' => (int) ($counts[self::FRESH] ?? 0),
            'hidden' => (int) ($counts[self::NOT_VISIBLE] ?? 0),
            'missing' => (int) ($counts[self::MISSING] ?? 0),
            'failed' => (int) ($counts[self::FAILED] ?? 0),
        ]);
    }
}
