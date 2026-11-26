<?php

namespace Restruct\Silverstripe\SEO\Tests\Stub\Report;

use Restruct\Silverstripe\SEO\Checks\CheckRunner;
use Restruct\Silverstripe\SEO\Checks\SiteData;
use Restruct\Silverstripe\SEO\Model\SeoCheckResult;
use RuntimeException;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Dev\TestOnly;
use SilverStripe\Security\Member;

/**
 * A check runner that throws for every page, registered in place of the real one, for the failure paths of the
 * site check (state put back, counted as failed).
 */
class SeoThrowingTestCheckRunner extends CheckRunner implements TestOnly
{
    public function checkPage(SiteTree $page, ?Member $member, ?SiteData $site = null): SeoCheckResult
    {
        throw new RuntimeException('The checks broke');
    }
}
