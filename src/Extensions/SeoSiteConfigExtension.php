<?php

namespace Restruct\Silverstripe\SEO\Extensions;

use SilverStripe\AssetAdmin\Forms\UploadField;
use SilverStripe\Assets\Image;
use SilverStripe\Core\Extension;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\TextField;
use SilverStripe\SiteConfig\SiteConfig;

/**
 * Site-wide SEO defaults: the sharing image used when a page has none, and the text appended to every `<title>`.
 *
 * @property string $SeoTitleSuffix
 * @property int $SeoDefaultImageID
 * @method Image SeoDefaultImage()
 * @extends Extension<SiteConfig>
 */
class SeoSiteConfigExtension extends Extension
{
    private static array $db = [
        'SeoTitleSuffix' => 'Varchar(255)',
    ];

    private static array $has_one = [
        'SeoDefaultImage' => Image::class,
    ];

    /**
     * SiteConfig is not versioned; saving it in the CMS publishes what it owns (versioned's
     * RecursivePublishableHandler), so the default image becomes publicly reachable when the settings are saved.
     */
    private static array $owns = [
        'SeoDefaultImage',
    ];

    protected function updateCMSFields(FieldList $fields): void
    {
        $config = $this->getOwner();
        # SiteConfig builds its fields by hand (no scaffolding), so adding them here cannot duplicate anything
        $fields->addFieldsToTab('Root.SEO', [
            TextField::create('SeoTitleSuffix', $config->fieldLabel('SeoTitleSuffix'))
                ->setAttribute('placeholder', (string) $config->Title)
                ->setDescription(_t(
                    __CLASS__ . '.SeoTitleSuffixHelp',
                    'Added after every page title in search results, e.g. "About us | Your Site". Leave empty to use the site title.'
                )),
            UploadField::create('SeoDefaultImage', $config->fieldLabel('SeoDefaultImage'))
                ->setAllowedFileCategories('image/supported')
                ->setFolderName('seo')
                ->setDescription(_t(
                    __CLASS__ . '.SeoDefaultImageHelp',
                    'Used when a page that is shared has no sharing image of its own. Best at 1200 x 630 pixels.'
                )),
        ]);
        $fields->fieldByName('Root.SEO')?->setTitle(_t(__CLASS__ . '.TabTitle', 'SEO'));
    }

    protected function updateFieldLabels(array &$labels): void
    {
        $labels['SeoTitleSuffix'] = _t(__CLASS__ . '.SeoTitleSuffix', 'Title suffix');
        $labels['SeoDefaultImage'] = _t(__CLASS__ . '.SeoDefaultImage', 'Default sharing image');
    }
}
