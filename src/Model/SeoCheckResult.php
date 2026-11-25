<?php

namespace Restruct\Silverstripe\SEO\Model;

use Restruct\Silverstripe\SEO\Checks\Check;
use Restruct\Silverstripe\SEO\Checks\CheckResult;
use SilverStripe\CMS\Model\SiteTree;
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
 * @method SiteTree Page()
 */
class SeoCheckResult extends DataObject
{
    private static string $table_name = 'SeoCheckResult';

    private static array $db = [
        'Stage' => 'Varchar(16)',
        'CheckedAt' => 'Datetime',
        'Results' => 'Text',
        'Problems' => 'Int',
        'Warnings' => 'Int',
        'Tips' => 'Int',
        'Stale' => 'Boolean',
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
    public static function store(SiteTree $page, string $stage, array $entries): static
    {
        $result = static::forPage($page, $stage) ?? static::create(['PageID' => (int) $page->ID, 'Stage' => $stage]);
        $result->CheckedAt = DBDatetime::now()->getValue();
        $result->Stale = false;
        $result->setEntries($entries);
        $result->write();
        return $result;
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
