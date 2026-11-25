<?php

namespace Restruct\Silverstripe\SEO\Tests\Stub\Checks;

use Restruct\Silverstripe\SEO\Model\SeoCheckResult;
use SilverStripe\Core\Extension;
use SilverStripe\Dev\TestOnly;
use SilverStripe\ORM\DB;

/**
 * Plays the other request of a race: when armed, a row for the same page and stage is inserted just before a
 * new result is written, as a second re-check finishing first would.
 *
 * @extends Extension<SeoCheckResult>
 */
class SeoRaceTestExtension extends Extension implements TestOnly
{
    public static bool $armed = false;

    protected function onBeforeWrite(): void
    {
        $owner = $this->getOwner();
        if (static::$armed && !$owner->isInDB()) {
            static::$armed = false;
            DB::prepared_query(
                'INSERT INTO "SeoCheckResult" ("ClassName", "Created", "LastEdited", "PageID", "Stage", "Results")'
                . ' VALUES (?, ?, ?, ?, ?, ?)',
                [SeoCheckResult::class, '2026-11-25 09:00:00', '2026-11-25 09:00:00', $owner->PageID, $owner->Stage, '{}']
            );
        }
    }
}
