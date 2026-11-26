<?php

namespace Restruct\Silverstripe\SEO\Extensions;

use SilverStripe\AssetAdmin\Forms\UploadField;
use SilverStripe\Assets\Image;
use SilverStripe\Control\Director;
use SilverStripe\Core\Convert;
use SilverStripe\Core\Extension;
use SilverStripe\Forms\EmailField;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\HeaderField;
use SilverStripe\Forms\LiteralField;
use SilverStripe\Forms\TextareaField;
use SilverStripe\Forms\TextField;
use SilverStripe\SiteConfig\SiteConfig;

/**
 * Site-wide SEO defaults: the sharing image used when a page has none, and the text appended to every `<title>`.
 *
 * Also the data of the site's Organization node in structured data: a logo, the organisation's profiles
 * elsewhere (sameAs), and its contact details (description, email, telephone, postal address). The node itself is
 * described by the `schema_map` on SiteConfig (see the module's config.yml), which a project extends with its own
 * fields. The contact details are also the place for other consumers of the organisation's data (a partner feed)
 * to read from.
 *
 * @property string $SeoTitleSuffix
 * @property string $SeoOrgDescription
 * @property string $SeoOrgEmail
 * @property string $SeoOrgTelephone
 * @property string $SeoOrgStreet
 * @property string $SeoOrgPostalCode
 * @property string $SeoOrgLocality
 * @property string $SeoOrgRegion
 * @property string $SeoOrgCountry
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
        # The organisation's contact details (structured data description, email, telephone, address)
        'SeoOrgDescription' => 'Text',
        # 254: the longest address SMTP allows
        'SeoOrgEmail' => 'Varchar(254)',
        'SeoOrgTelephone' => 'Varchar(50)',
        'SeoOrgStreet' => 'Varchar(255)',
        'SeoOrgPostalCode' => 'Varchar(20)',
        'SeoOrgLocality' => 'Varchar(100)',
        'SeoOrgRegion' => 'Varchar(100)',
        # An ISO 3166-1 alpha-2 code (NL) or a country name: schema.org accepts both, so it stays free text
        'SeoOrgCountry' => 'Varchar(100)',
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

    /**
     * The methods below, for the Organization node's schema_map (a map calls only methods it is allowed to).
     */
    private static array $schema_allowed_methods = [
        'SeoSchemaSiteURL',
        'SeoSchemaSameAs',
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
            HeaderField::create(
                'SeoOrgHeader',
                _t(__CLASS__ . '.SeoOrgHeader', 'Organisation contact details'),
                3
            ),
            LiteralField::create('SeoOrgIntro', '<p class="form__field-description">' . Convert::raw2xml(_t(
                __CLASS__ . '.SeoOrgIntro',
                'Shown to search engines as part of the organisation\'s structured data. The address is only used when it has a street or a place.'
            )) . '</p>'),
            TextareaField::create('SeoOrgDescription', $config->fieldLabel('SeoOrgDescription'))
                ->setRows(3),
            EmailField::create('SeoOrgEmail', $config->fieldLabel('SeoOrgEmail')),
            TextField::create('SeoOrgTelephone', $config->fieldLabel('SeoOrgTelephone'))
                ->setDescription(_t(
                    __CLASS__ . '.SeoOrgTelephoneHelp',
                    'Preferably in international format, e.g. +31 20 123 4567.'
                )),
            TextField::create('SeoOrgStreet', $config->fieldLabel('SeoOrgStreet')),
            TextField::create('SeoOrgPostalCode', $config->fieldLabel('SeoOrgPostalCode')),
            TextField::create('SeoOrgLocality', $config->fieldLabel('SeoOrgLocality')),
            TextField::create('SeoOrgRegion', $config->fieldLabel('SeoOrgRegion')),
            TextField::create('SeoOrgCountry', $config->fieldLabel('SeoOrgCountry'))
                ->setDescription(_t(
                    __CLASS__ . '.SeoOrgCountryHelp',
                    'A two-letter country code (NL) or the country\'s name.'
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
        $labels['SeoOrgDescription'] = _t(__CLASS__ . '.SeoOrgDescription', 'Description of the organisation');
        $labels['SeoOrgEmail'] = _t(__CLASS__ . '.SeoOrgEmail', 'Email address');
        $labels['SeoOrgTelephone'] = _t(__CLASS__ . '.SeoOrgTelephone', 'Telephone');
        $labels['SeoOrgStreet'] = _t(__CLASS__ . '.SeoOrgStreet', 'Street and number');
        $labels['SeoOrgPostalCode'] = _t(__CLASS__ . '.SeoOrgPostalCode', 'Postcode');
        $labels['SeoOrgLocality'] = _t(__CLASS__ . '.SeoOrgLocality', 'Place');
        $labels['SeoOrgRegion'] = _t(__CLASS__ . '.SeoOrgRegion', 'Province or region');
        $labels['SeoOrgCountry'] = _t(__CLASS__ . '.SeoOrgCountry', 'Country');
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
