<?php

namespace Restruct\Silverstripe\SEO\Tests\Stub\Checks;

use Restruct\Silverstripe\SEO\Checks\SiteData;
use SilverStripe\Dev\TestOnly;

/**
 * Counts how many SiteData objects a run creates and how often one computes the generated titles.
 */
class SeoSpySiteData extends SiteData implements TestOnly
{
    public static int $created = 0;

    public static int $titleLoads = 0;

    public function __construct()
    {
        static::$created++;
    }

    protected function loadComputedTitles(): array
    {
        static::$titleLoads++;
        return parent::loadComputedTitles();
    }
}
