<?php

namespace Restruct\Silverstripe\SEO\Forms;

use Restruct\Silverstripe\SEO\Checks\Rules\DescriptionLengthCheck;
use Restruct\Silverstripe\SEO\Checks\Rules\TitleWidthCheck;
use Restruct\Silverstripe\SEO\Text\TitleWidth;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Forms\FormField;
use SilverStripe\Model\ArrayData;
use SilverStripe\Model\List\ArrayList;
use SilverStripe\SiteConfig\SiteConfig;

/**
 * A search result preview (title, URL breadcrumb, description) with the title's estimated width and the
 * description's length, as of the last save. The script (client/dist/js/seo-checks.js) updates it on every
 * keystroke in the Title, Meta title and Meta description fields.
 *
 * The thresholds come from the checks' own config (TitleWidthCheck.max_pixels, DescriptionLengthCheck.min_length
 * and max_length) and the width table from {@see TitleWidth}, handed to the script as JSON in `data-seo-config`,
 * so the live counter and the stored checks use the same numbers.
 */
class SeoSnippetPreviewField extends FormField
{
    protected $schemaDataType = FormField::SCHEMA_DATA_TYPE_STRUCTURAL;

    public function __construct(string $name, ?string $title, protected SiteTree $page)
    {
        parent::__construct($name, $title);
        $this->addExtraClass('seo-snippet-field');
    }

    public function hasData()
    {
        return false;
    }

    public function performReadonlyTransformation()
    {
        return $this;
    }

    /**
     * @return array{title: array, description: array, url: array}
     */
    public function getClientConfig(): array
    {
        $page = $this->page;
        $config = SiteConfig::current_site_config();
        return [
            'title' => array_merge([
                'maxPixels' => (int) TitleWidthCheck::config()->get('max_pixels'),
                'separator' => (string) $page->config()->get('seo_title_separator'),
                'suffix' => trim((string) ($config->SeoTitleSuffix ?: $config->Title)),
                'appendSiteName' => (bool) $page->config()->get('seo_title_append_site_name'),
                # Set when the page decides its title in code: the script uses it instead of the form's inputs
                'pageTitle' => $page->SeoTitleFromCode() ? (string) $page->SeoPageTitle() : null,
            ], TitleWidth::singleton()->clientConfig()),
            'description' => [
                'min' => (int) DescriptionLengthCheck::config()->get('min_length'),
                'max' => (int) DescriptionLengthCheck::config()->get('max_length'),
                'fallback' => (string) $page->SeoDescriptionFallback(),
            ],
            'url' => [
                'host' => $this->host(),
                'path' => $this->pathSegments(),
            ],
        ];
    }

    public function getConfigJSON(): string
    {
        return (string) json_encode($this->getClientConfig());
    }

    public function getPreviewTitle(): string
    {
        return (string) $this->page->SeoTitle();
    }

    public function getPreviewDescription(): string
    {
        $description = (string) $this->page->SeoDescription();
        $max = (int) DescriptionLengthCheck::config()->get('max_length');
        return mb_strlen($description) > $max ? rtrim(mb_substr($description, 0, $max)) . '...' : $description;
    }

    public function getDescriptionLength(): int
    {
        return mb_strlen((string) $this->page->SeoDescription());
    }

    public function getTitlePixels(): int
    {
        return (int) round(TitleWidth::singleton()->pixels($this->getPreviewTitle()));
    }

    public function getMaxPixels(): int
    {
        return (int) TitleWidthCheck::config()->get('max_pixels');
    }

    public function getMinLength(): int
    {
        return (int) DescriptionLengthCheck::config()->get('min_length');
    }

    public function getMaxLength(): int
    {
        return (int) DescriptionLengthCheck::config()->get('max_length');
    }

    public function getHost(): string
    {
        return $this->host();
    }

    /**
     * @return ArrayList<ArrayData>
     */
    public function getPath(): ArrayList
    {
        return ArrayList::create(array_map(fn ($s) => ArrayData::create(['Segment' => $s]), $this->pathSegments()));
    }

    protected function host(): string
    {
        return (string) parse_url((string) $this->page->AbsoluteLink(), PHP_URL_HOST);
    }

    /**
     * @return string[]
     */
    protected function pathSegments(): array
    {
        $path = (string) parse_url((string) $this->page->AbsoluteLink(), PHP_URL_PATH);
        return array_values(array_filter(explode('/', trim(rawurldecode($path), '/')), fn ($s) => $s !== ''));
    }
}
