<?php

namespace Restruct\Silverstripe\SEO\Forms;

use Restruct\Silverstripe\SEO\Checks\Check;
use Restruct\Silverstripe\SEO\Checks\CheckResult;
use Restruct\Silverstripe\SEO\Checks\CheckRunner;
use Restruct\Silverstripe\SEO\Control\SeoCheckController;
use Restruct\Silverstripe\SEO\Model\SeoCheckResult;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Core\Convert;
use SilverStripe\Forms\FormField;
use SilverStripe\Model\ArrayData;
use SilverStripe\Model\List\ArrayList;
use SilverStripe\ORM\FieldType\DBDatetime;
use SilverStripe\ORM\FieldType\DBField;

/**
 * The SEO checks panel of a page: the counts ("1 problem, 2 warnings"), then the failing checks grouped, each
 * with its priority, message, details and how to fix it, the passed and ignored checks folded away below them,
 * and the skipped checks last. No score.
 *
 * Rendered on the server from the stored result, so it works without JavaScript. The script
 * (client/dist/js/seo-checks.js) adds the "re-check" and "ignore" buttons' behaviour, and re-checks after a save
 * and when the stored result is missing or out of date (`data-seo-stale="1"`): the render runs in its own request
 * to the CMS endpoint, never inside the form load or the save.
 *
 * Not a data field: nothing is read from it on save.
 */
class SeoChecksField extends FormField
{
    protected $schemaDataType = FormField::SCHEMA_DATA_TYPE_STRUCTURAL;

    protected SeoCheckResult|false|null $result = false;

    protected ?array $entries = null;

    public function __construct(string $name, ?string $title, protected SiteTree $page)
    {
        parent::__construct($name, $title);
        $this->addExtraClass('seo-checks-field');
    }

    public function hasData()
    {
        return false;
    }

    public function performReadonlyTransformation()
    {
        return $this;
    }

    public function getPage(): SiteTree
    {
        return $this->page;
    }

    /**
     * Read once per field: the template asks for it many times.
     */
    public function getResult(): ?SeoCheckResult
    {
        if ($this->result === false) {
            $this->result = $this->page->isInDB() ? SeoCheckResult::forPage($this->page) : null;
        }
        return $this->result;
    }

    /**
     * Whether the browser should ask for a fresh check: there is none yet, or it is out of date
     * ({@see SeoCheckResult::isStale()}).
     */
    public function getStale(): bool
    {
        $result = $this->getResult();
        return !$result || $result->isStale();
    }

    public function getHasResult(): bool
    {
        return $this->getResult() !== null;
    }

    public function getCheckedAt(): ?DBDatetime
    {
        $result = $this->getResult();
        return $result ? DBField::create_field('Datetime', $result->CheckedAt) : null;
    }

    /**
     * Messages for the panel's script, translated here and handed over in data-msg-* attributes: the script has
     * no translations of its own. {status} is filled in by the script.
     */
    public function getMsgChecking(): string
    {
        return _t(__CLASS__ . '.MsgChecking', 'Checking...');
    }

    public function getMsgNotStarted(): string
    {
        return _t(__CLASS__ . '.MsgNotStarted', 'Could not start the check.');
    }

    public function getMsgFailed(): string
    {
        # {status} is left for the script: _t() would fill it with an empty string
        return str_replace('[status]', '{status}', _t(__CLASS__ . '.MsgFailed', 'Check failed (HTTP [status]).'));
    }

    /**
     * Messages of the "About this check" dialog, which the script builds.
     */
    public function getMsgLoading(): string
    {
        return _t(__CLASS__ . '.MsgLoading', 'Loading...');
    }

    public function getMsgDocFailed(): string
    {
        return _t(__CLASS__ . '.MsgDocFailed', 'Could not load the explanation.');
    }

    public function getMsgDocLink(): string
    {
        return _t(__CLASS__ . '.MsgDocLink', 'Open the documentation');
    }

    public function getMsgClose(): string
    {
        return _t(__CLASS__ . '.MsgClose', 'Close');
    }

    public function getRecheckURL(): string
    {
        return SeoCheckController::singleton()->Link('recheck');
    }

    public function getIgnoreURL(): string
    {
        return SeoCheckController::singleton()->Link('ignore');
    }

    /**
     * "1 problem, 2 warnings, 3 tips", leaving out the zeroes; a positive sentence when everything passed.
     */
    public function getCountsText(): string
    {
        $result = $this->getResult();
        if (!$result) {
            return '';
        }
        $parts = [];
        foreach ([
            # i18n plurals: one form per CLDR category, pipe-separated
            Check::PROBLEM => [(int) $result->Problems, _t(__CLASS__ . '.CountProblems', '{count} problem|{count} problems', ['count' => (int) $result->Problems])],
            Check::WARNING => [(int) $result->Warnings, _t(__CLASS__ . '.CountWarnings', '{count} warning|{count} warnings', ['count' => (int) $result->Warnings])],
            Check::TIP => [(int) $result->Tips, _t(__CLASS__ . '.CountTips', '{count} tip|{count} tips', ['count' => (int) $result->Tips])],
        ] as [$count, $text]) {
            if ($count > 0) {
                $parts[] = $text;
            }
        }
        return $parts
            ? implode(', ', $parts)
            : _t(__CLASS__ . '.AllPassed', 'No problems, warnings or tips: every check passed.');
    }

    /**
     * Failing, not ignored entries, by group in the panel's order, most serious first within a group.
     *
     * @return ArrayList<ArrayData>
     */
    public function getGroups(): ArrayList
    {
        $byGroup = [];
        foreach ($this->entries() as $entry) {
            if ($entry['status'] === CheckResult::FAIL && empty($entry['ignored'])) {
                $byGroup[$entry['group'] ?? ''][] = $entry;
            }
        }
        $order = array_flip(Check::GROUPS);
        uksort($byGroup, fn ($a, $b) => ($order[$a] ?? 99) <=> ($order[$b] ?? 99));

        $groups = ArrayList::create();
        foreach ($byGroup as $group => $entries) {
            usort($entries, fn ($a, $b) => [static::rank($a['severity'], 'severity'), static::rank($a['priority'], 'priority')]
                <=> [static::rank($b['severity'], 'severity'), static::rank($b['priority'], 'priority')]);
            $groups->push(ArrayData::create([
                'Title' => static::groupLabel((string) $group),
                'Entries' => ArrayList::create(array_map(fn ($e) => $this->entryData($e), $entries)),
            ]));
        }
        return $groups;
    }

    /**
     * @return ArrayList<ArrayData>
     */
    public function getIgnored(): ArrayList
    {
        $list = ArrayList::create();
        foreach ($this->entries() as $entry) {
            if ($entry['status'] === CheckResult::FAIL && !empty($entry['ignored'])) {
                $list->push($this->entryData($entry));
            }
        }
        return $list;
    }

    /**
     * Checks that could not run (the page did not render, a check threw).
     *
     * @return ArrayList<ArrayData>
     */
    public function getSkipped(): ArrayList
    {
        $list = ArrayList::create();
        foreach ($this->entries() as $entry) {
            if ($entry['status'] === CheckResult::SKIPPED) {
                $list->push($this->entryData($entry));
            }
        }
        return $list;
    }

    /**
     * Passed checks, each under its pass title ("Title is unique"), in the order they ran.
     *
     * @return ArrayList<ArrayData>
     */
    public function getPassed(): ArrayList
    {
        $list = ArrayList::create();
        foreach ($this->entries() as $entry) {
            if ($entry['status'] === CheckResult::PASS) {
                $list->push($this->entryData($entry));
            }
        }
        return $list;
    }

    public function getPassedCount(): int
    {
        return count(array_filter($this->entries(), fn ($e) => $e['status'] === CheckResult::PASS));
    }

    public function getIgnoredCount(): int
    {
        return $this->getIgnored()->count();
    }

    /**
     * The summary of the folded list: "18 checks OK, 2 ignored", leaving out the ignored count when there are
     * none. Ignored checks are listed in the same fold as the passed ones (product owner's call, 2026-11-28).
     */
    public function getPassedSummary(): string
    {
        $passed = $this->getPassedCount();
        $ignored = $this->getIgnoredCount();
        # i18n plurals: one form per CLDR category, pipe-separated
        $parts = [_t(__CLASS__ . '.PassedOK', '{count} check OK|{count} checks OK', ['count' => $passed])];
        if ($ignored > 0) {
            $parts[] = _t(__CLASS__ . '.IgnoredCount', '{count} ignored|{count} ignored', ['count' => $ignored]);
        }
        return implode(', ', $parts);
    }

    /**
     * Whether the folded list has anything to show: passed or ignored checks.
     */
    public function getHasPassedList(): bool
    {
        return $this->getHasResult() && ($this->getPassedCount() > 0 || $this->getIgnoredCount() > 0);
    }

    /**
     * @return array<string, array>
     */
    protected function entries(): array
    {
        if ($this->entries === null) {
            $this->entries = CheckRunner::singleton()->renderEntries($this->getResult()?->getEntries() ?? []);
        }
        return $this->entries;
    }

    protected function entryData(array $entry): ArrayData
    {
        $ignoredBy = is_array($entry['ignoredBy'] ?? null) ? $entry['ignoredBy'] : null;
        return ArrayData::create([
            'ID' => (string) ($entry['id'] ?? ''),
            # Says what was found: the fail title for a failure, the pass title for a pass
            'Title' => (string) ($entry['title'] ?? ''),
            'Status' => (string) ($entry['status'] ?? ''),
            'StatusLabel' => static::statusLabel((string) ($entry['status'] ?? '')),
            'Severity' => (string) ($entry['severity'] ?? ''),
            'SeverityLabel' => static::severityLabel((string) ($entry['severity'] ?? '')),
            'PriorityLabel' => static::priorityLabel((string) ($entry['priority'] ?? '')),
            'Message' => (string) ($entry['message'] ?? ''),
            'Details' => ArrayList::create(array_map(
                fn ($detail, $html) => ArrayData::create([
                    'Text' => (string) $detail,
                    # Escaped by CheckMessage::html(), with only its own links to the Files admin
                    'HTML' => DBField::create_field('HTMLFragment', $html ?? Convert::raw2xml((string) $detail)),
                ]),
                array_values((array) ($entry['details'] ?? [])),
                array_pad(array_values((array) ($entry['detailsHtml'] ?? [])), count((array) ($entry['details'] ?? [])), null)
            )),
            'HowToFix' => (string) ($entry['howToFix'] ?? ''),
            'Docs' => (string) ($entry['docs'] ?? ''),
            # The CMS endpoint that renders the spec in a dialog, for a module check only; the Docs link stays the
            # fallback without the script, and the only link for a project's check
            'DocURL' => CheckRunner::singleton()->docFile((string) ($entry['id'] ?? ''))
                ? SeoCheckController::singleton()->Link('doc') . '?check=' . rawurlencode((string) $entry['id'])
                : '',
            # Tooltip and aria-label of the icon controls, here so the template can escape them for an attribute
            'IgnoreLabel' => _t(__CLASS__ . '.Ignore', 'Ignore for this page'),
            'UnignoreLabel' => _t(__CLASS__ . '.Unignore', 'Check again for this page'),
            'IgnoredStateLabel' => _t(__CLASS__ . '.StatusIgnored', 'Ignored'),
            'AboutLabel' => _t(__CLASS__ . '.MoreAbout', 'About this check'),
            # The render entry is not a check and cannot be ignored
            'CanIgnore' => ($entry['id'] ?? '') !== 'render',
            'IgnoredByName' => (string) ($ignoredBy['name'] ?? ''),
            'IgnoredDate' => $ignoredBy && !empty($ignoredBy['date'])
                ? DBField::create_field('Datetime', $ignoredBy['date'])->Date()
                : '',
        ]);
    }

    protected static function rank(string $value, string $axis): int
    {
        $order = $axis === 'severity'
            ? [Check::PROBLEM, Check::WARNING, Check::TIP]
            : [Check::HIGH, Check::MEDIUM, Check::LOW];
        $index = array_search($value, $order, true);
        return $index === false ? 9 : (int) $index;
    }

    public static function groupLabel(string $group): string
    {
        return match ($group) {
            Check::GROUP_INDEXING => _t(__CLASS__ . '.GroupIndexing', 'Indexing'),
            Check::GROUP_TITLE => _t(__CLASS__ . '.GroupTitle', 'Title'),
            Check::GROUP_DESCRIPTION => _t(__CLASS__ . '.GroupDescription', 'Description'),
            Check::GROUP_CONTENT => _t(__CLASS__ . '.GroupContent', 'Content'),
            Check::GROUP_IMAGES => _t(__CLASS__ . '.GroupImages', 'Images'),
            Check::GROUP_LINKS => _t(__CLASS__ . '.GroupLinks', 'Links'),
            Check::GROUP_STRUCTURED_DATA => _t(__CLASS__ . '.GroupStructuredData', 'Structured data'),
            Check::GROUP_SOCIAL => _t(__CLASS__ . '.GroupSocial', 'Social'),
            Check::GROUP_URL => _t(__CLASS__ . '.GroupUrl', 'URL'),
            default => _t(__CLASS__ . '.GroupOther', 'Other'),
        };
    }

    /**
     * The marker next to the severity badge, so the outcome reads at a glance and not only from the heading.
     * The panel shows it for passed and skipped checks only: on a failure the fail title and the severity badge
     * already say it (product owner's call, 2026-11-27).
     */
    public static function statusLabel(string $status): string
    {
        return match ($status) {
            CheckResult::FAIL => _t(__CLASS__ . '.StatusFail', 'Not met'),
            CheckResult::PASS => _t(__CLASS__ . '.StatusPass', 'OK'),
            default => _t(__CLASS__ . '.StatusSkipped', 'Not checked'),
        };
    }

    public static function severityLabel(string $severity): string
    {
        return match ($severity) {
            Check::PROBLEM => _t(__CLASS__ . '.SeverityProblem', 'Problem'),
            Check::WARNING => _t(__CLASS__ . '.SeverityWarning', 'Warning'),
            default => _t(__CLASS__ . '.SeverityTip', 'Tip'),
        };
    }

    public static function priorityLabel(string $priority): string
    {
        return match ($priority) {
            Check::HIGH => _t(__CLASS__ . '.PriorityHigh', 'High priority'),
            Check::MEDIUM => _t(__CLASS__ . '.PriorityMedium', 'Medium priority'),
            default => _t(__CLASS__ . '.PriorityLow', 'Low priority'),
        };
    }
}
