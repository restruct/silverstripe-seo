<?php

namespace Restruct\Silverstripe\SEO\Migration\Mappers;

use Restruct\Silverstripe\SEO\Migration\MigrationResult;
use Restruct\Silverstripe\SEO\Migration\MigrationRun;
use Restruct\Silverstripe\SEO\Migration\SeoMigrationMapper;
use SilverStripe\Core\Injector\Injectable;

/**
 * jellygnite/silverstripe-seo (5.0, a Silverstripe 5 module: its data is found in a site upgraded to 6). Its
 * extensions are applied to Page. The Facebook text and image win over the Twitter ones, as one sharing
 * description and image serve both here.
 */
class JellygniteMapper implements SeoMigrationMapper
{
    use Injectable;

    protected const PAGE = ['Page', 'SiteTree'];

    public function getTitle(): string
    {
        return 'jellygnite/silverstripe-seo';
    }

    public function migrate(MigrationRun $run): void
    {
        $run->copyColumn(self::PAGE, 'MetaTitle', 'SiteTree', 'MetaTitle');
        $run->copyColumn(self::PAGE, 'CanonicalURL', 'SiteTree', 'CanonicalURL', $run->canonicalTransform());
        $run->copyColumn(self::PAGE, ['FacebookPageDescription', 'TwitterPageDescription'], 'SiteTree', 'SEOFBdescription');
        $run->copyColumn(
            self::PAGE,
            ['FacebookPageImageID', 'TwitterPageImageID'],
            'SiteTree',
            'OGImageID',
            $run->imageTransform(),
            true
        );

        $title = 'og:title is the meta title here; copy it to MetaTitle by hand where it should win';
        $run->reportColumn(self::PAGE, 'FacebookPageTitle', MigrationResult::REPORTED, $title);
        $run->reportColumn(self::PAGE, 'TwitterPageTitle', MigrationResult::REPORTED, $title);
        $run->reportColumn(self::PAGE, 'FacebookPageType', MigrationResult::REPORTED, 'og:type is set per page class (seo_og_type)');
        $run->reportColumn(self::PAGE, 'CreatorID', MigrationResult::DROPPED);
        $run->reportColumn(self::PAGE, 'FocusKeyword', MigrationResult::DROPPED, 'focus keyphrases are not used here');
        foreach (['GoogleAnalyticsTrackingID', 'GoogleAnalytics', 'FacebookPixel', 'TwitterPixel', 'SnapPixel'] as $column) {
            $run->reportColumn(['SiteConfig'], $column, MigrationResult::REPORTED, 'tracking code is not part of this module');
        }
        $run->reportColumn(['SiteConfig'], 'TwitterAccountName', MigrationResult::DROPPED);
    }
}
