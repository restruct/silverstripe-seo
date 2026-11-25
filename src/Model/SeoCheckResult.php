<?php

namespace Restruct\Silverstripe\SEO\Model;

use Restruct\Silverstripe\SEO\Checks\Check;
use Restruct\Silverstripe\SEO\Checks\CheckResult;
use Restruct\Silverstripe\SEO\Checks\CheckRunner;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Core\Validation\ValidationException;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\FieldType\DBDatetime;
use SilverStripe\Security\Permission;
use SilverStripe\Versioned\Versioned;

/**
 * The stored outcome of the SEO checks for one page in one stage: the entries as JSON, and the number of failing
 * (not ignored) checks per severity, so a report can filter and sort without decoding the JSON.
 *
 * `Stale` is set when the page is saved after the check: the panel then asks for a fresh check (the browser calls
 * the re-check endpoint once the edit form has loaded), so the render never happens inside the write itself.
 *
 * @property int $PageID
 * @property string $Stage
 * @property string $CheckedAt
 * @property string $Results
 * @property int $Problems
 * @property int $Warnings
 * @property int $Tips
 * @property bool $Stale
 * @property int $Format
 * @property string $ConfigHash
 * @property bool $CrossPage
 * @method SiteTree Page()
 */
class SeoCheckResult extends DataObject
{
    /**
     * Results hold message keys and parameters, translated when shown ({@see CheckMessage}).
     */
    public const FORMAT = 2;

    private static string $table_name = 'SeoCheckResult';

    private static string $singular_name = 'SEO check result';

    private static string $plural_name = 'SEO check results';

    private static array $db = [
        'Stage' => 'Varchar(16)',
        'CheckedAt' => 'Datetime',
        'Results' => 'Text',
        'Problems' => 'Int',
        'Warnings' => 'Int',
        'Tips' => 'Int',
        'Stale' => 'Boolean',
        # The shape of Results: 0 for rows of the first version (finished text), FORMAT for message keys
        'Format' => 'Int',
        # The check configuration it was checked under (CheckRunner::configHash()); another one makes it stale
        'ConfigHash' => 'Varchar(40)',
        # Holds a check that reads other pages: stale when any page changed after it was checked
        'CrossPage' => 'Boolean',
    ];

    private static array $has_one = [
        'Page' => SiteTree::class,
    ];

    private static array $indexes = [
        'PageStage' => [
            'type' => 'unique',
            'columns' => ['PageID', 'Stage'],
        ],
    ];

    private static string $default_sort = '"CheckedAt" DESC';

    public static function forPage(SiteTree $page, string $stage = Versioned::DRAFT): ?static
    {
        return static::get()->filter(['PageID' => (int) $page->ID, 'Stage' => $stage])->first();
    }

    /**
     * Store fresh entries for a page and stage, replacing what was there.
     *
     * @param array<string, array> $entries
     */
    /**
     * @param string|null $checkedAt When the check started (default now): anything edited after it makes the
     *                               result stale ({@see isStale()}), also edits made while the check ran
     */
    public static function store(SiteTree $page, string $stage, array $entries, bool $stale = false, ?string $checkedAt = null): static
    {
        $checkedAt ??= DBDatetime::now()->getValue();
        $result = static::forPage($page, $stage) ?? static::create(['PageID' => (int) $page->ID, 'Stage' => $stage]);
        static::fill($result, $entries, $stale, $checkedAt);
        try {
            $result->write();
        } catch (ValidationException $e) {
            # Another request (a second re-check) inserted the row between our lookup and our insert: the unique
            # index refuses ours, so update theirs. Anything else is not ours to swallow.
            $existing = $result->isInDB() ? null : static::forPage($page, $stage);
            if (!$existing) {
                throw $e;
            }
            $result = $existing;
            static::fill($result, $entries, $stale, $checkedAt);
            $result->write();
        }
        return $result;
    }

    protected static function fill(SeoCheckResult $result, array $entries, bool $stale, string $checkedAt): void
    {
        $result->CheckedAt = $checkedAt;
        $result->Stale = $stale;
        $result->Format = static::FORMAT;
        $result->ConfigHash = CheckRunner::singleton()->configHash();
        $result->setEntries($entries);
    }

    /**
     * Mark every stored result of the page out of date (after the page was saved).
     */
    public static function markStale(SiteTree $page): void
    {
        foreach (static::get()->filter(['PageID' => (int) $page->ID, 'Stale' => false]) as $result) {
            $result->Stale = true;
            $result->write();
        }
    }

    /**
     * Out of date when any of these holds:
     * - flagged on a save of the page or one of its blocks, or a row of an earlier format;
     * - checked under another check configuration (ids, thresholds, CheckRunner::CHECKS_VERSION);
     * - older than the last change of the page or of a block in its Elemental areas (for writes that bypassed
     *   the hooks, a query or an import);
     * - it holds a check that reads other pages (duplicates, links, inbound links) and any page changed since.
     *
     * Pass a {@see StaleContext} to decide many results with a fixed number of queries; without one, this
     * builds its own (a few small queries).
     */
    public function isStale(?StaleContext $context = null): bool
    {
        return static::isStaleRow($this->toMap(), $context);
    }

    /**
     * {@see isStale()} on the raw columns of a row (PageID, Stage, CheckedAt, Stale, Format, ConfigHash,
     * CrossPage), so a report can decide without loading the results JSON.
     *
     * @param array<string, mixed> $row
     */
    public static function isStaleRow(array $row, ?StaleContext $context = null): bool
    {
        # A row of an earlier format holds finished text in the language of whoever ran it
        if (!empty($row['Stale']) || (int) ($row['Format'] ?? 0) < static::FORMAT) {
            return true;
        }
        $stage = (string) ($row['Stage'] ?? '') ?: Versioned::DRAFT;
        if (!$context || $context->getStage() !== $stage) {
            $context = StaleContext::create($stage);
        }
        if ((string) ($row['ConfigHash'] ?? '') !== $context->configHash()) {
            return true;
        }
        $checkedAt = (string) ($row['CheckedAt'] ?? '');
        if (!empty($row['CrossPage']) && $context->siteEdited() > $checkedAt) {
            return true;
        }
        $pageID = (int) ($row['PageID'] ?? 0);
        $edited = $context->pageEdited($pageID);
        if ($edited === null || $edited > $checkedAt) {
            return true;
        }
        return $context->blocksEdited($pageID) > $checkedAt;
    }

    /**
     * Mark the entries the editors ignored for this page, with who did it and when.
     *
     * @param array<string, array> $entries
     * @return array<string, array>
     */
    public static function applyIgnores(SiteTree $page, array $entries): array
    {
        $ignores = [];
        foreach (SeoCheckIgnore::get()->filter('PageID', (int) $page->ID) as $ignore) {
            $ignores[$ignore->CheckID] = $ignore;
        }
        foreach ($entries as $id => $entry) {
            $ignore = $ignores[$id] ?? null;
            $entries[$id]['ignored'] = $ignore !== null;
            $entries[$id]['ignoredBy'] = $ignore ? [
                'memberID' => (int) $ignore->MemberID,
                'name' => $ignore->Member()->exists() ? $ignore->Member()->getName() : '',
                'date' => (string) $ignore->Created,
            ] : null;
        }
        return $entries;
    }

    /**
     * @return array<string, array> The entries, keyed by check id
     */
    public function getEntries(): array
    {
        $entries = json_decode((string) $this->Results, true);
        return is_array($entries) ? $entries : [];
    }

    /**
     * @param array<string, array> $entries
     */
    public function setEntries(array $entries): static
    {
        $this->Results = (string) json_encode($entries, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        $counts = static::countEntries($entries);
        $this->Problems = $counts[Check::PROBLEM];
        $this->Warnings = $counts[Check::WARNING];
        $this->Tips = $counts[Check::TIP];
        $this->CrossPage = (bool) array_filter($entries, fn ($entry) => !empty($entry['crossPage']));
        return $this;
    }

    /**
     * Re-read the ignores and recount, after an editor ignored or un-ignored a check.
     */
    public function recount(): static
    {
        $page = $this->Page();
        if ($page && $page->exists()) {
            $this->setEntries(static::applyIgnores($page, $this->getEntries()));
            $this->write();
        }
        return $this;
    }

    /**
     * Failing, not ignored entries per severity.
     *
     * @param array<string, array> $entries
     * @return array{problem:int, warning:int, tip:int}
     */
    public static function countEntries(array $entries): array
    {
        $counts = [Check::PROBLEM => 0, Check::WARNING => 0, Check::TIP => 0];
        foreach ($entries as $entry) {
            if (($entry['status'] ?? '') === CheckResult::FAIL && empty($entry['ignored'])
                && isset($counts[$entry['severity'] ?? ''])
            ) {
                $counts[$entry['severity']]++;
            }
        }
        return $counts;
    }

    /**
     * Readable by whoever may edit the page the result is about.
     */
    public function canView($member = null)
    {
        $page = $this->Page();
        return $page && $page->exists() ? $page->canEdit($member) : Permission::check('ADMIN', 'any', $member);
    }

    public function canEdit($member = null)
    {
        return $this->canView($member);
    }

    public function canDelete($member = null)
    {
        return $this->canView($member);
    }

    public function canCreate($member = null, $context = [])
    {
        return Permission::check('CMS_ACCESS_CMSMain', 'any', $member);
    }
}
