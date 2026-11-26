<?php

namespace Restruct\Silverstripe\SEO\Checks;

use Psr\Log\LoggerInterface;
use Restruct\Silverstripe\RenderedContentExtractor\PageEligibility;
use Restruct\Silverstripe\RenderedContentExtractor\PageRenderer;
use Restruct\Silverstripe\RenderedContentExtractor\RenderResult;
use Restruct\Silverstripe\SEO\Model\SeoCheckResult;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\ORM\FieldType\DBDatetime;
use SilverStripe\ORM\Queries\SQLSelect;
use SilverStripe\Security\Member;
use SilverStripe\Versioned\Versioned;
use Throwable;

/**
 * Runs the registered checks on a page and stores the outcome.
 *
 * The page is rendered once, in the draft stage, as the member passed in (the CMS user who asked), through
 * {@see PageRenderer}. The stored result is shared: it reflects the render of whoever ran the check last, and is
 * shown to every member who may edit the page (the panel is left out for anyone else, and the endpoint
 * refuses them). A page that renders differently per editor (content shown only to some groups) can therefore
 * show one editor findings from what another editor saw; only results, never the rendered HTML, are stored. The render is parsed once and shared by every check through {@see PageAnalysis}.
 *
 * Gating: a page that asks not to be indexed gets only the checks marked `runs_on_noindex` (the "is this
 * intentional?" check), and is not rendered at all. Page types the shared {@see PageEligibility} excludes
 * (redirectors, virtual pages, error pages) get no checks.
 *
 * Checks are registered in YAML, keyed by id:
 *
 * ```yaml
 * Restruct\Silverstripe\SEO\Checks\CheckRunner:
 *   checks:
 *     thin-content:
 *       enabled: false
 *     my-check:
 *       class: App\Seo\MyCheck
 * ```
 */
class CheckRunner
{
    use Configurable;
    use Injectable;

    /**
     * The id of the entry that reports a page that could not be rendered.
     */
    public const RENDER_ENTRY = 'render';

    /**
     * The version of the check logic, part of {@see configHash()}: raise it when a release changes what a check
     * finds, so every stored result is checked again.
     */
    public const CHECKS_VERSION = 1;

    /**
     * id => [class, enabled]. Set in the module's YAML; a project adds or disables entries in its own.
     */
    private static array $checks = [];

    /**
     * Where the spec of a module check is published; `%s` is the check id. Linked from the panel, so an
     * explanation can be improved without a release. Only for the module's own checks: a project's check links
     * to what its docsUrl() returns, or to nothing.
     */
    private static string $docs_url = 'https://github.com/restruct/silverstripe-seo/blob/main/docs/checks/%s.md';

    /**
     * @var array<string, Check>|null
     */
    protected ?array $instances = null;

    /**
     * The enabled checks, instantiated, keyed by id, in registration order.
     *
     * @return array<string, Check>
     */
    public function getChecks(): array
    {
        if ($this->instances !== null) {
            return $this->instances;
        }
        $this->instances = [];
        foreach ((array) static::config()->get('checks') as $id => $definition) {
            $definition = is_array($definition) ? $definition : ['class' => $definition];
            $class = (string) ($definition['class'] ?? '');
            if (array_key_exists('enabled', $definition) && !$definition['enabled']) {
                continue;
            }
            if ($class === '' || !class_exists($class)) {
                $this->logger()->warning(sprintf('[seo] Check "%s" has no usable class (%s); skipped', $id, $class));
                continue;
            }
            $check = Injector::inst()->create($class);
            if (!$check instanceof Check) {
                $this->logger()->warning(sprintf('[seo] Check "%s" (%s) does not implement Check; skipped', $id, $class));
                continue;
            }
            $this->instances[(string) $id] = $check;
        }
        return $this->instances;
    }

    /**
     * Whether a check id is registered and enabled.
     */
    public function hasCheck(string $id): bool
    {
        return isset($this->getChecks()[$id]);
    }

    /**
     * Run the checks on the draft of a page and return the entries, keyed by check id, translated into the
     * current locale, without storing them. Ignores recorded for the page are applied (an ignored failure keeps
     * its status and is marked `ignored`).
     *
     * @param Member|null $member Who to render as; null renders as an anonymous visitor
     * @return array<string, array> See {@see entry()} for the shape, with every text a string
     */
    public function run(SiteTree $page, ?Member $member): array
    {
        return $this->renderEntries($this->collect($page, $member));
    }

    /**
     * The entries as stored: messages as {@see CheckMessage} arrays (key, default, parameters), so they are
     * translated when shown.
     *
     * @return array<string, array>
     */
    public function collect(SiteTree $page, ?Member $member): array
    {
        if (!PageEligibility::singleton()->isEligible($page, 'seo')) {
            return [];
        }
        $draft = Versioned::get_by_stage(SiteTree::class, Versioned::DRAFT)->byID($page->ID);
        if (!$draft instanceof SiteTree) {
            return [];
        }

        # Field values and site lookups are read in the draft stage, as the render is
        $entries = Versioned::withVersionedMode(function () use ($draft, $member) {
            Versioned::set_stage(Versioned::DRAFT);

            $site = SiteData::create();
            $noindex = PageAnalysis::create($draft, null, $site)->isNoindex();
            $render = $noindex ? null : PageRenderer::singleton()->render($draft, Versioned::DRAFT, $member);
            $analysis = PageAnalysis::create($draft, $render, $site);

            $entries = [];
            if ($render && !$analysis->isRendered()) {
                $entries[self::RENDER_ENTRY] = $this->renderEntry($render);
            }
            foreach ($this->getChecks() as $id => $check) {
                if ($noindex && !$this->runsOnNoindex($check)) {
                    continue;
                }
                $entries[$id] = $this->entry($id, $check, $this->runCheck($check, $analysis));
            }
            return $entries;
        });

        return SeoCheckResult::applyIgnores($page, $entries);
    }

    /**
     * Run the checks and store the outcome as the page's draft result.
     */
    public function checkPage(SiteTree $page, ?Member $member): SeoCheckResult
    {
        $startedAt = DBDatetime::now()->getValue();
        $before = $this->draftLastEdited($page);
        $entries = $this->collect($page, $member);
        # Saved while the check ran (another tab, a block): the result describes an older draft, so store it as
        # out of date and let the panel ask again
        $changed = $this->draftLastEdited($page) !== $before;
        return SeoCheckResult::store($page, Versioned::DRAFT, $entries, $changed, $startedAt);
    }

    protected function draftLastEdited(SiteTree $page): string
    {
        # Straight from the draft table: the page object in memory does not see a save made by another request
        return (string) SQLSelect::create('"LastEdited"', '"SiteTree"', ['"ID"' => (int) $page->ID])->execute()->value();
    }

    /**
     * The docs link of a check: its own docsUrl() if it has one, else the module's spec for a module check.
     */
    protected function docsFor(Check $check, string $id): string
    {
        $own = method_exists($check, 'docsUrl') ? (string) $check->docsUrl() : '';
        if ($own !== '') {
            return $own;
        }
        $moduleNamespace = 'Restruct\\Silverstripe\\SEO\\Checks\\Rules\\';
        return str_starts_with(get_class($check), $moduleNamespace)
            ? sprintf((string) static::config()->get('docs_url'), rawurlencode($id))
            : '';
    }

    /**
     * Stored entries in the current locale: message, details and how-to-fix translated; the title and general
     * advice of a registered check taken from the check itself. Entries of the earlier format (plain text) are
     * shown as they are.
     *
     * @param array<string, array> $entries
     * @return array<string, array>
     */
    public function renderEntries(array $entries): array
    {
        $checks = $this->getChecks();
        foreach ($entries as $id => $entry) {
            $check = $checks[$id] ?? null;
            $entry['title'] = $check ? $check->title() : CheckMessage::text($entry['title'] ?? '');
            $entry['message'] = CheckMessage::text($entry['message'] ?? '');
            $entry['details'] = array_map([CheckMessage::class, 'text'], (array) ($entry['details'] ?? []));
            $howToFix = CheckMessage::text($entry['howToFix'] ?? '');
            if (($entry['status'] ?? '') === CheckResult::FAIL && $howToFix === '' && $check) {
                $howToFix = $check->howToFix();
            }
            $entry['howToFix'] = ($entry['status'] ?? '') === CheckResult::FAIL ? $howToFix : '';
            $entries[$id] = $entry;
        }
        return $entries;
    }

    protected function runCheck(Check $check, PageAnalysis $analysis): CheckResult
    {
        if ($check->needsRender() && !$analysis->isRendered()) {
            return CheckResult::skipped(CheckMessage::_t(__CLASS__ . '.SkippedNoRender', 'Not checked: the page could not be rendered.'));
        }
        try {
            return $check->run($analysis);
        } catch (Throwable $e) {
            # One faulty check (a project's own, say) costs that check, never the panel
            $this->logger()->warning(sprintf('[seo] Check "%s" failed: %s', $check->id(), $e->getMessage()), [
                'exception' => $e,
            ]);
            return CheckResult::skipped(CheckMessage::_t(__CLASS__ . '.SkippedError', 'Not checked: the check failed ({message}).', [
                'message' => $e->getMessage(),
            ]));
        }
    }

    /**
     * The stored and transported form of one result.
     *
     * @return array{id:string, group:string, severity:string, priority:string, title:string, status:string,
     *               message:string|array, details:array, howToFix:string|array, docs:string, ignored:bool,
     *               ignoredBy:?array}
     */
    protected function entry(string $id, Check $check, CheckResult $result): array
    {
        return [
            'id' => $id,
            'group' => $check->group(),
            'severity' => $check->severity(),
            'priority' => $check->priority(),
            # Shown from the check itself in the reader's language; kept for a check that is later removed
            'title' => $check->title(),
            'status' => $result->getStatus(),
            'message' => $result->getMessage(),
            'details' => $result->getDetails(),
            # Only advice specific to this result is stored; the check's general advice is added when shown
            'howToFix' => $result->isFail() ? $result->getHowToFix() : '',
            'docs' => $this->docsFor($check, $id),
            # Depends on other pages too: the stored result goes stale when any page changes
            'crossPage' => $this->isCrossPage($check),
            'ignored' => false,
            'ignoredBy' => null,
        ];
    }

    protected function isCrossPage(Check $check): bool
    {
        return method_exists($check, 'isCrossPage') && $check->isCrossPage();
    }

    /**
     * A fingerprint of what the checks are: the enabled check ids, their classes and each class's config
     * (thresholds, severity, priority), the stored format and {@see CHECKS_VERSION}. Stored with every result; a
     * result checked under another fingerprint is stale. Read from config each time (not from the instances
     * kept by getChecks()), so a change made in this process counts too.
     */
    public function configHash(): string
    {
        $parts = ['version' => static::CHECKS_VERSION, 'format' => SeoCheckResult::FORMAT, 'checks' => []];
        foreach ((array) static::config()->get('checks') as $id => $definition) {
            $definition = is_array($definition) ? $definition : ['class' => $definition];
            if (array_key_exists('enabled', $definition) && !$definition['enabled']) {
                continue;
            }
            $class = (string) ($definition['class'] ?? '');
            $parts['checks'][(string) $id] = [
                'class' => $class,
                'config' => $class !== '' && class_exists($class) ? Config::inst()->get($class) : null,
            ];
        }
        ksort($parts['checks']);
        return sha1((string) json_encode($parts, JSON_PARTIAL_OUTPUT_ON_ERROR));
    }

    protected function renderEntry(RenderResult $render): array
    {
        $status = $render->getStatusCode();
        $message = $status >= 300 && $status < 400
            ? CheckMessage::_t(__CLASS__ . '.RenderRedirected', 'The page did not render: it redirected (status {status}). The checks that read the rendered page were skipped.', ['status' => $status])
            : CheckMessage::_t(__CLASS__ . '.RenderFailed', 'The page did not render (status {status}). The checks that read the rendered page were skipped.', ['status' => $status]);
        return [
            'id' => self::RENDER_ENTRY,
            'group' => Check::GROUP_INDEXING,
            'severity' => Check::PROBLEM,
            'priority' => Check::HIGH,
            'title' => CheckMessage::_t(__CLASS__ . '.RenderTitle', 'The page renders'),
            'status' => CheckResult::FAIL,
            'message' => $message,
            'details' => array_values(array_filter([(string) $render->getError()])),
            'howToFix' => CheckMessage::_t(__CLASS__ . '.RenderHowToFix', 'Preview the draft. If it shows an error or sends you elsewhere, fix that first, then re-check.'),
            'docs' => '',
            'crossPage' => false,
            'ignored' => false,
            'ignoredBy' => null,
        ];
    }

    protected function runsOnNoindex(Check $check): bool
    {
        return $check instanceof AbstractCheck && (bool) $check::config()->get('runs_on_noindex');
    }

    protected function logger(): LoggerInterface
    {
        return Injector::inst()->get(LoggerInterface::class);
    }
}
