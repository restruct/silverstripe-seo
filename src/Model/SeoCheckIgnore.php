<?php

namespace Restruct\Silverstripe\SEO\Model;

use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\ORM\DataObject;
use SilverStripe\Security\Member;
use SilverStripe\Security\Permission;

/**
 * An editor's "this page is fine as it is" for one check on one page: the check still runs and its result is
 * kept, but it is listed as ignored and not counted. Who ignored it is the Member; when is Created.
 *
 * @property int $PageID
 * @property int $MemberID
 * @property string $CheckID
 * @method SiteTree Page()
 * @method Member Member()
 */
class SeoCheckIgnore extends DataObject
{
    private static string $table_name = 'SeoCheckIgnore';

    private static array $db = [
        'CheckID' => 'Varchar(100)',
    ];

    private static array $has_one = [
        'Page' => SiteTree::class,
        'Member' => Member::class,
    ];

    private static array $indexes = [
        'PageCheck' => [
            'type' => 'unique',
            'columns' => ['PageID', 'CheckID'],
        ],
    ];

    /**
     * Ignore a check for a page (once: ignoring again keeps the first record) and recount its stored results.
     */
    public static function ignore(SiteTree $page, string $checkID, ?Member $member): static
    {
        $ignore = static::get()->filter(['PageID' => (int) $page->ID, 'CheckID' => $checkID])->first();
        if (!$ignore) {
            $ignore = static::create([
                'PageID' => (int) $page->ID,
                'CheckID' => $checkID,
                'MemberID' => (int) $member?->ID,
            ]);
            $ignore->write();
        }
        static::recountPage($page);
        return $ignore;
    }

    public static function unignore(SiteTree $page, string $checkID): void
    {
        foreach (static::get()->filter(['PageID' => (int) $page->ID, 'CheckID' => $checkID]) as $ignore) {
            $ignore->delete();
        }
        static::recountPage($page);
    }

    protected static function recountPage(SiteTree $page): void
    {
        foreach (SeoCheckResult::get()->filter('PageID', (int) $page->ID) as $result) {
            $result->recount();
        }
    }

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
