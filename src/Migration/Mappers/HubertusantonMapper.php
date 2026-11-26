<?php

namespace Restruct\Silverstripe\SEO\Migration\Mappers;

use Restruct\Silverstripe\SEO\Migration\MigrationResult;
use Restruct\Silverstripe\SEO\Migration\MigrationRun;
use Restruct\Silverstripe\SEO\Migration\SeoMigrationMapper;
use SilverStripe\Core\Injector\Injectable;

/**
 * hubertusanton/silverstripe-seo (6.x). Its extension is applied to Page, so its columns are on the Page table
 * (or on `_obsolete_Page` once db:build has renamed that table).
 */
class HubertusantonMapper implements SeoMigrationMapper
{
    use Injectable;

    # SiteTree as a fallback, for a project that applied the extension there instead
    protected const PAGE = ['Page', 'SiteTree'];

    public function getTitle(): string
    {
        return 'hubertusanton/silverstripe-seo';
    }

    public function migrate(MigrationRun $run): void
    {
        $run->copyColumn(self::PAGE, 'SEOSocialImageID', 'SiteTree', 'OGImageID', $run->imageTransform(), true);
        $run->reportColumn(
            self::PAGE,
            'SEOHideSocialData',
            MigrationResult::REPORTED,
            _t(__CLASS__ . '.HideSocial', 'no per-page switch here; to leave out Open Graph site-wide set SeoSiteTreeExtension.seo_output_open_graph: false')
        );
        $run->reportColumn(self::PAGE, 'SEOPageSubject', MigrationResult::DROPPED, _t(__CLASS__ . '.FocusKeyphrase', 'focus keyphrases are not used here'));
        $run->reportColumn(self::PAGE, 'SEOSocialType', MigrationResult::DROPPED, _t(__CLASS__ . '.OgType', 'og:type is set per page class (seo_og_type)'));
        $run->reportColumn(
            ['SiteConfig'],
            'GoogleWebmasterMetaTag',
            MigrationResult::REPORTED,
            _t(__CLASS__ . '.Verification', 'put the verification tag in the page template, or verify the site through DNS')
        );
    }
}
