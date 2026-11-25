<?php

namespace Restruct\Silverstripe\SEO\Extensions;

use SilverStripe\AssetAdmin\Forms\UploadField;
use SilverStripe\Assets\Image;
use SilverStripe\Control\Director;
use SilverStripe\Core\Extension;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\TextareaField;
use SilverStripe\Forms\TextField;
use SilverStripe\SiteConfig\SiteConfig;

/**
 * Site-wide SEO defaults: the sharing image used when a page has none, and the text appended to every `<title>`.
 *
 * Also the data of the site's Organization node in structured data: a logo and the organisation's profiles
 * elsewhere (sameAs). The node itself is described by the `schema_map` on SiteConfig (see the module's
 * config.yml), which a project extends with its own fields (address, phone, ...).
 *
 * @property string $SeoTitleSuffix
 * @property int $SeoDefaultImageID
 * @property int $SeoLogoID
 * @property string $SeoSameAs
 * @method Image SeoDefaultImage()
 * @method Image SeoLogo()
 * @extends Extension<SiteConfig>
 */
class SeoSiteConfigExtension extends Extension
{
    private static array $db = [
        'SeoTitleSuffix' => 'Varchar(255)',
        # The organisation's profiles on other sites, one URL per line (structured data sameAs)
        'SeoSameAs' => 'Text',
    ];

    private static array $has_one = [
        'SeoDefaultImage' => Image::class,
        # The organisation's logo in structured data; Google wants at least 112 x 112 pixels, legible on white
        'SeoLogo' => Image::class,
    ];

    /**
     * SiteConfig is not versioned; saving it in the CMS publishes what it owns (versioned's
     * RecursivePublishableHandler), so the default image becomes publicly reachable when the settings are saved.
     */
    private static array $owns = [
        'SeoDefaultImage',
        'SeoLogo',
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
            UploadField::create('SeoLogo', $config->fieldLabel('SeoLogo'))
                ->setAllowedFileCategories('image/supported')
                ->setFolderName('seo')
                ->setDescription(_t(
                    __CLASS__ . '.SeoLogoHelp',
                    'The organisation\'s logo for search engines. At least 112 x 112 pixels, legible on a white background.'
                )),
            TextareaField::create('SeoSameAs', $config->fieldLabel('SeoSameAs'))
                ->setRows(4)
                ->setDescription(_t(
                    __CLASS__ . '.SeoSameAsHelp',
                    'The organisation\'s pages on other sites (social media, a business register), one URL per line.'
                )),
        ]);
        $fields->fieldByName('Root.SEO')?->setTitle(_t(__CLASS__ . '.TabTitle', 'SEO'));
    }

    protected function updateFieldLabels(array &$labels): void
    {
        $labels['SeoTitleSuffix'] = _t(__CLASS__ . '.SeoTitleSuffix', 'Title suffix');
        $labels['SeoDefaultImage'] = _t(__CLASS__ . '.SeoDefaultImage', 'Default sharing image');
        $labels['SeoLogo'] = _t(__CLASS__ . '.SeoLogo', 'Logo');
        $labels['SeoSameAs'] = _t(__CLASS__ . '.SeoSameAs', 'Profiles on other sites');
    }

    /**
     * The site's home URL, for the Organization node's `url`.
     */
    public function SeoSchemaSiteURL(): string
    {
        return rtrim((string) Director::absoluteBaseURL(), '/') . '/';
    }

    /**
     * The http(s) URLs in SeoSameAs, one per line; anything else on a line is skipped.
     *
     * @return string[]
     */
    public function SeoSchemaSameAs(): array
    {
        $urls = [];
        foreach (preg_split('/\R/', (string) $this->getOwner()->SeoSameAs) ?: [] as $line) {
            $line = trim($line);
            if ($line !== '' && preg_match('#^https?://[^\s/]+#i', $line) && filter_var($line, FILTER_VALIDATE_URL)) {
                $urls[] = $line;
            }
        }
        return $urls;
    }
}
