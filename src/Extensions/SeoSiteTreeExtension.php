<?php

namespace Restruct\Silverstripe\SEO\Extensions;

use Psr\Log\LoggerInterface;
use Restruct\Silverstripe\SEO\Model\SubjectMetadata;
use Restruct\Silverstripe\SEO\Schema\PageSchema;
use Restruct\Silverstripe\SEO\Text\DescriptionSummary;
use SilverStripe\AssetAdmin\Forms\UploadField;
use SilverStripe\Assets\Image;
use SilverStripe\Assets\Storage\AssetStore;
use SilverStripe\CMS\Controllers\ContentController;
use SilverStripe\CMS\Model\RedirectorPage;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\CMS\Model\VirtualPage;
use SilverStripe\Control\Controller;
use SilverStripe\Control\Director;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Core\Convert;
use SilverStripe\Core\Extension;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Core\Validation\ValidationResult;
use SilverStripe\Forms\CheckboxField;
use SilverStripe\Forms\CompositeField;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\FormField;
use SilverStripe\Forms\HeaderField;
use SilverStripe\Forms\LiteralField;
use SilverStripe\Forms\ReadonlyTransformation;
use SilverStripe\Forms\Tab;
use SilverStripe\Forms\TabSet;
use SilverStripe\Forms\TextareaField;
use SilverStripe\Forms\TextField;
use SilverStripe\Forms\TreeDropdownField;
use SilverStripe\i18n\i18n;
use SilverStripe\Model\List\SS_List;
use SilverStripe\ORM\DataObject;
use SilverStripe\Security\Member;
use SilverStripe\SiteConfig\SiteConfig;
use SilverStripe\Versioned\Versioned;
use Throwable;

/**
 * Search and sharing metadata for pages: meta title, a description fallback, robots directives, canonical URL and
 * Open Graph, with the fallbacks each of them needs.
 *
 * Column names are chosen to coincide with what other SEO modules already store, so their data is picked up
 * without a migration: `MetaTitle` (shared with kinglozzer/metatitle and others), the four `MetaRobots*` flags
 * and `SEOFBdescription`. `MetaDescription` stays core's own field.
 *
 * Everything reaches the page head through core's `updateMetaComponents` hook (the SS6 name; the SS5-era
 * `MetaComponents`/`MetaTags` extension methods are never called on SS6). The matching response headers are set
 * by {@see SeoContentControllerExtension}.
 *
 * Values are read as a template reads them, so a page class that decides them in code is respected: a getter
 * override (`getMetaTitle()`, `getMetaRobotsNoIndex()`, ...) wins over the column, and a `MetaDescriptionFallback()`
 * method supplies the fallback description before the module derives one.
 *
 * When the page's controller shows a record on a sub-action and declares it with `setSeoSubject()`, the title,
 * description, canonical, sharing values and (if the record has its own) robots come from that record instead;
 * see {@see SubjectMetadata}.
 *
 * Extension hooks offered to projects and later versions of this module, all called on the page:
 * - `updateSeoTitle(string &$title)`: the full `<title>` text, suffix included;
 * - `updateSeoDescriptionFallback(?string &$description)`: the description used when none was typed (the
 *   page's own `MetaDescriptionFallback()` when it has one and it returns text, else the content summary);
 * - `updateSeoRobotsDirectives(array &$directives)`: the robots directives, e.g. to add `max-image-preview`;
 * - `updateSeoCanonicalURL(?string &$url, ?HTTPRequest $request)`;
 * - `updateSeoImageFallback(?Image &$image)`: the sharing image when the page has none of its own;
 * - `updateSeoMetaComponents(array &$tags)`: every head tag this module produced, just before output. The
 *   structured data is the `structured-data` entry.
 * - `updateSeoSchemaGraph(SchemaGraph $graph, SchemaContext $ctx)`: the structured-data graph of the page view,
 *   before incomplete nodes are left out (see {@see PageSchema}).
 *
 * @property string $MetaTitle
 * @property bool $MetaRobotsNoIndex
 * @property bool $MetaRobotsNoFollow
 * @property bool $MetaRobotsNoSnippet
 * @property bool $MetaRobotsNoCache
 * @property string $CanonicalURL
 * @property int $CanonicalPageID
 * @method SiteTree CanonicalPage()
 * @property string $SEOFBdescription
 * @property int $OGImageID
 * @method Image OGImage()
 * @extends Extension<SiteTree>
 */
class SeoSiteTreeExtension extends Extension
{
    /**
     * The form fields this extension adds to a page, by field name.
     */
    protected const EDITABLE_FIELDS = [
        'MetaTitle',
        'MetaRobotsNoIndex',
        'MetaRobotsNoFollow',
        'MetaRobotsNoSnippet',
        'MetaRobotsNoCache',
        'CanonicalPageID',
        'CanonicalURL',
        'SEOFBdescription',
        'OGImage',
    ];

    /**
     * Names a project may use in `seo_hidden_fields` / `seo_readonly_fields` for a field named after its column.
     */
    protected const FIELD_ALIASES = [
        'CanonicalPage' => 'CanonicalPageID',
    ];

    private static array $db = [
        'MetaTitle' => 'Varchar(255)',
        'MetaRobotsNoIndex' => 'Boolean',
        'MetaRobotsNoFollow' => 'Boolean',
        'MetaRobotsNoSnippet' => 'Boolean',
        # Named "NoCache" in the module this column comes from; output as the directive that exists: noarchive
        'MetaRobotsNoCache' => 'Boolean',
        # 2048: the practical upper bound browsers and search engines accept for a URL
        'CanonicalURL' => 'Varchar(2048)',
        # The Open Graph description; the column name is kept so existing content is picked up as-is
        'SEOFBdescription' => 'Text',
    ];

    private static array $has_one = [
        'OGImage' => Image::class,
        # A page of this site as the canonical, picked in the page tree; wins over CanonicalURL, which stays for
        # an original on another site. Its published link is read when the page renders (SeoCanonicalURL)
        'CanonicalPage' => SiteTree::class,
    ];

    /**
     * Publishing the page publishes its sharing image: a draft-only image is not reachable for the crawlers of
     * the sites the page is shared on.
     */
    private static array $owns = [
        'OGImage',
    ];

    /**
     * SS6's SiteTree scaffolds every database field an extension adds; these are placed explicitly in
     * updateCMSFields instead, so without this list each one would appear twice.
     */
    private static array $scaffold_cms_fields_settings = [
        'ignoreFields' => [
            'MetaTitle',
            'MetaRobotsNoIndex',
            'MetaRobotsNoFollow',
            'MetaRobotsNoSnippet',
            'MetaRobotsNoCache',
            'CanonicalURL',
            'CanonicalPage',
            'CanonicalPageID',
            'SEOFBdescription',
            'OGImage',
        ],
    ];

    /**
     * Between the page's own title and the site name in `<title>`.
     */
    private static string $seo_title_separator = ' | ';

    /**
     * Append the site name (or SiteConfig.SeoTitleSuffix when set) to `<title>`.
     */
    private static bool $seo_title_append_site_name = true;

    /**
     * Extensions of other modules that already put a MetaTitle field on pages, on the same column (the
     * `MetaTitle` column is shared). When the page has one of them, this module adds no MetaTitle field of its
     * own; the title output still reads the column. A list, so a project's YAML adds to it.
     */
    private static array $seo_meta_title_field_providers = [
        'Kinglozzer\\SilverStripeMetaTitle\\MetaTitleExtension',
    ];

    /**
     * Fields of this module (and core's MetaDescription, which sits with them) that a page class leaves out of
     * its edit form, by field name: `MetaTitle` on a page type whose title is generated, say. The value is still
     * read for output; it is only no longer editable. Other names are ignored. A list, so a subclass adds to its
     * parent's.
     */
    private static array $seo_hidden_fields = [];

    /**
     * The same fields shown read-only instead of hidden: the editor sees the value, but cannot change it.
     */
    private static array $seo_readonly_fields = [];

    /**
     * The tab the SEO fields, the checks panel and the search result preview go on, as a path from the form's
     * root. A tab the module creates is placed right after Main. Meant to be a tab of its own: the panel and the
     * preview are put at its top.
     */
    private static string $seo_tab = 'Root.SEO';

    /**
     * Remove core's Metadata toggle from the Main tab once its fields (MetaDescription, ExtraMeta) have moved to
     * the SEO tab and nothing else is left in it. A toggle that other code filled before this extension ran is
     * kept. Set to false when a page class (after parent::getCMSFields()) or an extension that runs later looks
     * up `Root.Main.Metadata` to add to it: it would find nothing there.
     */
    private static bool $seo_remove_empty_metadata_toggle = true;

    /**
     * Derive a description from the page when the editor typed none.
     */
    private static bool $seo_description_fallback = true;

    /**
     * Maximum length of the derived description, in characters. About what Google shows on desktop.
     */
    private static int $seo_description_fallback_length = 155;

    /**
     * Send `noindex, nofollow` (meta tag and header) on every page when the environment is not live
     * (Director::isLive()), so a test or acceptance site that is reachable from outside stays out of search
     * results. Off by default.
     */
    private static bool $seo_noindex_non_live = false;

    /**
     * Which robots directive each flag column stands for, in output order. Associative on purpose: a project can
     * then add its own flag column without the list merging into duplicates.
     */
    private static array $seo_robots_directives = [
        'MetaRobotsNoIndex' => 'noindex',
        'MetaRobotsNoFollow' => 'nofollow',
        'MetaRobotsNoSnippet' => 'nosnippet',
        'MetaRobotsNoCache' => 'noarchive',
    ];

    /**
     * Query parameters that make a different page and so stay in the self-referencing canonical (for example
     * `start` for pagination). Every other parameter (tracking, sorting, `stage`) is dropped from it.
     */
    private static array $seo_canonical_query_params = [];

    /**
     * Query parameters stripped from a shown record's own link before it becomes the canonical (shell wildcards).
     * A list, so a project's YAML adds to it.
     */
    private static array $seo_tracking_query_params = [
        'utm_*',
        'gclid',
        'gbraid',
        'wbraid',
        'fbclid',
        'msclkid',
        'mc_cid',
        'mc_eid',
        '_ga',
    ];

    /**
     * og:type, set per page class in YAML (e.g. `article` on a news article class).
     */
    private static string $seo_og_type = 'website';

    /**
     * Where a sharing image comes from when the page has none of its own, before the `updateSeoImageFallback`
     * hook and the site default: names of relations or methods on the page, tried in order. Each may be a has_one
     * image, a has_many or many_many of images (its first usable image), or a method returning an image or a
     * list of them. A name the page does not have, a value that is not an image (a File relation, say), an
     * empty relation and an image that is not public are passed over. Empty by default.
     */
    private static array $seo_image_fallback = [];

    /**
     * The sharing image is cropped to this size (1.91:1, what the large link previews use). Keyed, so a project
     * can override one value in YAML without the two arrays merging. Set to an empty array to use the original.
     */
    private static array $seo_og_image_size = [
        'width' => 1200,
        'height' => 630,
    ];

    /**
     * Print `<link rel="canonical">` and send the `Link: <...>; rel="canonical"` header. Switch off when the theme
     * already prints a canonical, so there are never two. `$SeoCanonicalURL` stays available to templates.
     */
    private static bool $seo_output_canonical = true;

    /**
     * Print the Open Graph tags (og:*, og:url included) and `twitter:card`. Switch off when the theme already
     * prints its own sharing tags.
     */
    private static bool $seo_output_open_graph = true;

    /**
     * twitter:card value when there is an image; without one it is always `summary`. Set to an empty string to
     * leave the tag out (X falls back to the Open Graph tags).
     */
    private static string $seo_twitter_card = 'summary_large_image';

    /**
     * The timezone of the dates and times in structured data (a PHP timezone name such as `Europe/Amsterdam`).
     * Date, Time and Datetime fields are stored without one, as local time of the site; the offset is added on
     * output. Empty: PHP's default timezone, which is what Silverstripe reads the stored values with.
     */
    private static string $seo_schema_timezone = '';

    /**
     * Print the structured data (one `<script type="application/ld+json">` with the page's `@graph`). Switch off
     * when the theme or another module already prints JSON-LD for the same things.
     */
    private static bool $seo_output_structured_data = true;

    /**
     * The schema.org type of the page's WebPage node, per page class: `AboutPage`, `ContactPage`,
     * `CollectionPage` (a listing), `ItemPage`, ...
     */
    private static string $schema_webpage_type = 'WebPage';

    /**
     * A relation or method of the page whose records the page lists: output as an ItemList of their URLs, which
     * becomes the page's main entity when it has none of its own. Empty: no ItemList.
     */
    private static string $schema_item_list = '';

    /**
     * At most this many entries in the ItemList; 0 for no limit.
     */
    private static int $schema_item_list_limit = 100;

    /**
     * Relations or methods of the page whose records are added as nodes of their own (each must be a structured
     * data source). A list, so a project's YAML adds to it.
     */
    private static array $schema_includes = [];

    /**
     * At most this many included records per page view, over all of `schema_includes`; 0 for no limit. Each is
     * mapped on every page view, as nothing is cached.
     */
    private static int $schema_includes_limit = 50;

    /**
     * Page methods a structured-data map, `schema_includes` or `schema_item_list` may call (besides fields,
     * relations, getters and cast methods): the child pages and the links. A list, so a project's YAML adds to it.
     */
    private static array $schema_allowed_methods = [
        'Children',
        'AllChildren',
        'Link',
        'AbsoluteLink',
    ];

    # --- CMS -----------------------------------------------------------------------------------------------------

    protected function updateCMSFields(FieldList $fields): void
    {
        $page = $this->getOwner();
        # A redirector page never renders, so robots, canonical and sharing settings would have no effect there
        if ($page instanceof RedirectorPage) {
            return;
        }

        # Everything on a tab of its own; the checks panel and the preview are put at its top by SeoChecksExtension
        $tab = static::findOrMakeSeoTab($fields, $page);

        # Another module (kinglozzer/metatitle) may already add a field for the same column. SS6's insertBefore()
        # would replace that field with this one, or this one with that, depending on the extension order. Its
        # field, when it is already there, moves to the tab like core's; one added later lands before
        # MetaDescription, which is on the tab by then
        if ($this->metaTitleFieldProvidedElsewhere()) {
            $this->moveToTab($fields, 'MetaTitle', $tab);
        } else {
            $tab->push(
                TextField::create('MetaTitle', $page->fieldLabel('MetaTitle'))
                    ->setAttribute('placeholder', $page->Title)
                    ->setDescription(_t(
                        __CLASS__ . '.MetaTitleHelp',
                        'Shown as the link text in search results. Leave empty to use the page title. The site name is added automatically.'
                    ))
            );
        }

        # Core's description, out of core's Metadata toggle, right after the meta title
        $description = $this->moveToTab($fields, 'MetaDescription', $tab);
        # Show editors the description search engines get when they leave the field empty
        if ($description && $this->descriptionFallback() !== '') {
            $description->setAttribute('placeholder', $this->descriptionFallback());
        }

        $sharingImage = UploadField::create('OGImage', $page->fieldLabel('OGImage'))
            ->setAllowedFileCategories('image/supported')
            ->setFolderName('seo')
            ->setDescription(_t(
                __CLASS__ . '.OGImageHelp',
                'Shown when the page is shared on social media or in chat apps. Best at 1200 x 630 pixels. Leave empty to use the site default.'
            ));

        $extraFields = [
            # One group, titled in the label column, the checkboxes stacked in the field column with each
            # description under its own label. Separate checkboxes under a heading left an empty label column in
            # front of each; nested in a CompositeField the admin styles align them with the other fields
            CompositeField::create(
                CheckboxField::create('MetaRobotsNoIndex', $page->fieldLabel('MetaRobotsNoIndex'))
                    ->setDescription(_t(
                        __CLASS__ . '.NoIndexHelp',
                        'The page stays reachable, but asks search engines not to list it. It is also left out of the sitemap.'
                    )),
                CheckboxField::create('MetaRobotsNoFollow', $page->fieldLabel('MetaRobotsNoFollow')),
                CheckboxField::create('MetaRobotsNoSnippet', $page->fieldLabel('MetaRobotsNoSnippet')),
                CheckboxField::create('MetaRobotsNoCache', $page->fieldLabel('MetaRobotsNoCache'))
            )->setName('SeoRobots')->setTitle(_t(__CLASS__ . '.RobotsHeader', 'Search engine indexing')),
            # A page of this site, picked in the tree: it follows the page when it is renamed or moved
            TreeDropdownField::create('CanonicalPageID', $page->fieldLabel('CanonicalPage'), SiteTree::class)
                # The page itself is its own canonical already
                ->setDisableFunction(fn ($node) => $page->isInDB() && (int) $node->ID === (int) $page->ID)
                ->setDescription(_t(
                    __CLASS__ . '.CanonicalPageHelp',
                    'Only when this page duplicates another page of this site: choose the original. It takes precedence over the canonical URL below, and is used once it is published. Leave empty and the page points to itself.'
                )),
            TextField::create('CanonicalURL', $page->fieldLabel('CanonicalURL'))
                ->setAttribute('placeholder', $page->isInDB() ? (string) $this->SeoCanonicalURL() : '')
                ->setDescription(_t(
                    __CLASS__ . '.CanonicalHelp',
                    'Only when this page duplicates a page on another site: the full URL of the original (https://...). For a page of this site, choose it under Canonical page instead, which takes precedence. Leave both empty and the page points to itself.'
                )),
            HeaderField::create('SeoSharingHeader', _t(__CLASS__ . '.SharingHeader', 'Social media and sharing'), 4),
            TextareaField::create('SEOFBdescription', $page->fieldLabel('SEOFBdescription'))
                ->setRows(3)
                ->setDescription(_t(
                    __CLASS__ . '.OGDescriptionHelp',
                    'Leave empty to use the meta description.'
                )),
            $sharingImage,
        ];
        foreach ($extraFields as $field) {
            $tab->push($field);
        }
        # Before core's free-form ExtraMeta, which stays last as the advanced option
        $this->moveToTab($fields, 'ExtraMeta', $tab);
        $this->removeEmptyMetadataToggle($fields);

        # Read-only, after everything editable: what the page would output as structured data. Not before the
        # first save, when the page has no URL to build ids on
        if ($page->isInDB() && $page->config()->get('seo_output_structured_data')) {
            $tab->push(HeaderField::create(
                'SeoSchemaHeader',
                _t(__CLASS__ . '.SchemaHeader', 'Structured data'),
                4
            ));
            $tab->push(LiteralField::create('SeoSchemaPreview', $this->schemaPreviewHtml()));
        }

        if ($page instanceof VirtualPage) {
            $this->makeVirtualFieldsReadonly($page, $fields);
        }

        $this->applyFieldRules($fields);
    }

    /**
     * The page's SEO tab (`seo_tab`), created with the title "SEO" and placed right after Main when it is not
     * there yet. Shared with {@see SeoChecksExtension}, so whichever extension runs first creates it.
     */
    public static function findOrMakeSeoTab(FieldList $fields, SiteTree $page): Tab
    {
        $path = trim((string) $page->config()->get('seo_tab')) ?: 'Root.SEO';
        $existing = $fields->findTab($path);
        if ($existing instanceof Tab) {
            return $existing;
        }
        $parts = explode('.', $path);
        $name = (string) end($parts);
        $tab = $fields->findOrMakeTab($path, $name === 'SEO' ? _t(__CLASS__ . '.TabTitle', 'SEO') : null);
        # findOrMakeTab() appends: move a tab of the root tab set to right after Main
        $parent = count($parts) > 1 ? $fields->findTab(implode('.', array_slice($parts, 0, -1))) : null;
        if ($parent instanceof TabSet && $parent->fieldByName('Main') && $name !== 'Main') {
            $parent->getChildren()->removeByName($name);
            $parent->insertAfter('Main', $tab);
        }
        return $tab;
    }

    /**
     * Move a data field from wherever it is (core's Metadata toggle, say) to the end of the tab. Returns the
     * field, or null when the form does not have it (a page class removed it).
     */
    protected function moveToTab(FieldList $fields, string $name, Tab $tab): ?FormField
    {
        $field = $fields->dataFieldByName($name);
        if (!$field) {
            return null;
        }
        $fields->removeByName($name, true);
        $tab->push($field);
        return $field;
    }

    /**
     * Core's Metadata toggle, once MetaDescription and ExtraMeta have left it: removed when nothing else is in
     * it (see `seo_remove_empty_metadata_toggle`), kept for fields another module put there.
     */
    protected function removeEmptyMetadataToggle(FieldList $fields): void
    {
        if (!$this->getOwner()->config()->get('seo_remove_empty_metadata_toggle')) {
            return;
        }
        $toggle = $fields->fieldByName('Root.Main.Metadata');
        if ($toggle instanceof CompositeField && $toggle->getChildren()->count() === 0) {
            $fields->removeFieldFromTab('Root.Main', 'Metadata');
        }
    }

    /**
     * `seo_hidden_fields` and `seo_readonly_fields` of the page class. Only this module's fields and
     * MetaDescription are touched; a hidden field also wins over a read-only one.
     */
    protected function applyFieldRules(FieldList $fields): void
    {
        $config = $this->getOwner()->config();
        $own = array_merge(static::EDITABLE_FIELDS, ['MetaDescription']);
        # The relation's name (CanonicalPage) stands for its field (CanonicalPageID)
        $names = fn (array $list) => array_map(fn ($name) => static::FIELD_ALIASES[$name] ?? $name, $list);
        $hidden = array_values(array_intersect($own, $names((array) $config->get('seo_hidden_fields'))));
        foreach ($hidden as $name) {
            # Searches the tabs and the Metadata toggle; only the data field, never a container of that name
            $fields->removeByName($name, true);
        }

        $transformation = ReadonlyTransformation::create();
        $readonly = array_diff(array_intersect($own, $names((array) $config->get('seo_readonly_fields'))), $hidden);
        foreach ($readonly as $name) {
            $field = $fields->dataFieldByName($name);
            if ($field && !$field->isReadonly()) {
                $fields->replaceField($name, $field->transform($transformation));
            }
        }
    }

    /**
     * The graph as pretty-printed JSON and the registry's warnings, all escaped. A failure while building it
     * (a project's provideSchemaData throwing, say) is shown instead of breaking the edit form.
     */
    protected function schemaPreviewHtml(): string
    {
        try {
            # The CMS runs in the editor's language; the structured data is in the site's
            $schema = PageSchema::create($this->getOwner())
                ->setLocale((string) i18n::config()->get('default_locale'));
            $json = $schema->getGraph()->toJson(JSON_PRETTY_PRINT);
            $warnings = $schema->getWarnings();
        } catch (Throwable $e) {
            return '<p class="alert alert-danger">' . Convert::raw2xml(_t(
                __CLASS__ . '.SchemaPreviewFailed',
                'The structured data could not be built: {message}',
                ['message' => $e->getMessage()]
            )) . '</p>';
        }

        $html = '<p class="form__field-description">' . Convert::raw2xml(_t(
            __CLASS__ . '.SchemaPreviewHelp',
            'What search engines get from this page as structured data, after the last save. Built from the page and the records it shows; nodes that miss required information are left out.'
        )) . '</p>';

        $required = array_filter($warnings, fn (array $w) => $w['severity'] === 'required');
        $recommended = array_filter($warnings, fn (array $w) => $w['severity'] !== 'required');
        foreach ([[$required, 'alert-warning'], [$recommended, 'alert-info']] as [$list, $class]) {
            if ($list) {
                $html .= '<ul class="alert ' . $class . '">';
                foreach ($list as $warning) {
                    $html .= '<li>' . Convert::raw2xml($warning['message']) . '</li>';
                }
                $html .= '</ul>';
            }
        }

        return $html . '<pre class="seo-schema-preview" style="max-height:30em;overflow:auto;white-space:pre-wrap">'
            . Convert::raw2xml($json) . '</pre>';
    }

    /**
     * Whether an extension listed in `seo_meta_title_field_providers` is applied to this page. Checked by class
     * name, with class_exists first, so listing a module that is not installed costs nothing.
     */
    protected function metaTitleFieldProvidedElsewhere(): bool
    {
        $page = $this->getOwner();
        foreach ((array) $page->config()->get('seo_meta_title_field_providers') as $class) {
            if ($class && class_exists($class) && $page->hasExtension($class)) {
                return true;
            }
        }
        return false;
    }

    /**
     * A virtual page copies these columns from its source on every write (VirtualPage::getVirtualFields() and
     * refreshFromCopied()), so an editable field would lose its input on save. Core makes its own copied fields
     * read-only in a beforeUpdateCMSFields callback, which runs before this extension added its fields; the same
     * transformation is applied here to ours.
     */
    protected function makeVirtualFieldsReadonly(VirtualPage $page, FieldList $fields): void
    {
        $virtual = $page->getVirtualFields();
        if (!$virtual) {
            # No source page chosen yet: nothing is copied, so nothing is overwritten
            return;
        }
        $transformation = ReadonlyTransformation::create();
        foreach (static::EDITABLE_FIELDS as $name) {
            # A has_one's column is OGImageID, its field OGImage
            $column = $name === 'OGImage' ? 'OGImageID' : $name;
            $field = $fields->dataFieldByName($name);
            if ($field && in_array($column, $virtual, true) && !$field->isReadonly()) {
                $fields->replaceField($name, $field->transform($transformation));
            }
        }
    }

    protected function updateFieldLabels(array &$labels): void
    {
        $labels['MetaTitle'] = _t(__CLASS__ . '.MetaTitle', 'Meta title');
        $labels['MetaRobotsNoIndex'] = _t(__CLASS__ . '.MetaRobotsNoIndex', 'Hide from search results (noindex)');
        $labels['MetaRobotsNoFollow'] = _t(__CLASS__ . '.MetaRobotsNoFollow', 'Do not follow the links on this page (nofollow)');
        $labels['MetaRobotsNoSnippet'] = _t(__CLASS__ . '.MetaRobotsNoSnippet', 'No text snippet in search results (nosnippet)');
        $labels['MetaRobotsNoCache'] = _t(__CLASS__ . '.MetaRobotsNoCache', 'No cached copy in search engines (noarchive)');
        $labels['CanonicalURL'] = _t(__CLASS__ . '.CanonicalURL', 'Canonical URL');
        $labels['CanonicalPage'] = _t(__CLASS__ . '.CanonicalPage', 'Canonical page');
        $labels['CanonicalPageID'] = $labels['CanonicalPage'];
        $labels['SEOFBdescription'] = _t(__CLASS__ . '.SEOFBdescription', 'Sharing description');
        $labels['OGImage'] = _t(__CLASS__ . '.OGImage', 'Sharing image');
    }

    protected function updateValidate(ValidationResult $result): void
    {
        $url = trim((string) $this->getOwner()->CanonicalURL);
        if ($url !== '' && !static::isValidCanonicalInput($url)) {
            $result->addFieldError(
                'CanonicalURL',
                _t(__CLASS__ . '.CanonicalInvalid', 'The canonical URL must start with https://, http:// or /.')
            );
        }
    }

    /**
     * An absolute http(s) URL with a host, or a root-relative path. Anything else (javascript:, mailto:, a bare
     * word, a protocol-relative //host) is refused, because it ends up in a link element and a response header.
     */
    public static function isValidCanonicalInput(string $url): bool
    {
        if (str_starts_with($url, '/')) {
            return !str_starts_with($url, '//') && !preg_match('/\s/', $url);
        }
        $parts = parse_url($url);
        return is_array($parts)
            && in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
            && !empty($parts['host'])
            && !preg_match('/\s/', $url);
    }

    # --- Values (also usable in templates: $SeoTitle, $SeoDescription, ...) ---------------------------------------

    /**
     * The page's own title for search and sharing: the meta title, or the page title.
     */
    public function SeoPageTitle(): string
    {
        if ($subject = $this->SeoSubjectMetadata()) {
            return $subject->title();
        }
        $page = $this->getOwner();
        return trim((string) ($page->MetaTitle ?: $page->Title));
    }

    /**
     * The complete `<title>` text: page title, separator, site name. Plain text; escape when outputting.
     */
    public function SeoTitle(): string
    {
        $page = $this->getOwner();
        $title = $this->SeoPageTitle();
        $suffix = $this->titleSuffix();

        if ($suffix !== '' && $page->config()->get('seo_title_append_site_name')) {
            # Never "Site | Site" or "Courses | Site | Site": a repeated site name is one of the reasons Google
            # gives for rewriting a title
            if ($title === '') {
                $title = $suffix;
            } elseif (!static::endsWithSiteName($title, $suffix)) {
                $title .= $page->config()->get('seo_title_separator') . $suffix;
            }
        }

        $page->extend('updateSeoTitle', $title);
        return $title;
    }

    /**
     * The meta description: the editor's text, or else the fallback.
     */
    public function SeoDescription(): string
    {
        if ($subject = $this->SeoSubjectMetadata()) {
            return $subject->description();
        }
        $typed = trim((string) $this->getOwner()->MetaDescription);
        return $typed !== '' ? $typed : $this->descriptionFallback();
    }

    /**
     * The description derived from the page when none is typed (empty when the fallback is switched off). Shown
     * as the field's placeholder and in the search result preview.
     */
    public function SeoDescriptionFallback(): string
    {
        return $this->descriptionFallback();
    }

    /**
     * The robots directives as one comma-separated value, or an empty string when the page is indexable and
     * followable (which is what search engines assume anyway, so nothing is output).
     */
    public function SeoRobots(): string
    {
        return implode(', ', $this->SeoRobotsDirectives());
    }

    /**
     * @return string[]
     */
    public function SeoRobotsDirectives(): array
    {
        $page = $this->getOwner();
        # A shown record with robots settings of its own decides; one without them follows the page
        $directives = $this->SeoSubjectMetadata()?->robotsDirectives();
        if ($directives === null) {
            $directives = [];
            foreach ((array) $page->config()->get('seo_robots_directives') as $field => $directive) {
                # Read as a template reads it (ModelData::__get): a page class's getter override, such as a
                # getMetaRobotsNoIndex() that forces noindex on archived pages, wins over the column
                if ($directive && $page->__get($field)) {
                    $directives[] = $directive;
                }
            }
        }
        # Site-wide, so it also overrides a shown record that wants to be indexed
        if ($page->config()->get('seo_noindex_non_live') && !Director::isLive()) {
            $directives = array_merge(['noindex', 'nofollow'], $directives);
        }
        $page->extend('updateSeoRobotsDirectives', $directives);
        return array_values(array_unique(array_filter($directives)));
    }

    /**
     * The absolute canonical URL, or null for a page that has no URL of its own (an unsaved record, or the
     * placeholder page the Security controller renders) and for an error page, which answers for URLs that failed.
     *
     * Without an override this is the page's own URL, without its query string except for the parameters in
     * `seo_canonical_query_params`. An action URL (`/page/action/id`) is its own canonical rather than pointing
     * at the page, since it shows different content. A shown record (SEO subject) gives its own link; a picked
     * canonical page ({@see SeoCanonicalPage()}) its published link, before the free-text override; a virtual
     * page points at the page it copies, since it is a duplicate of that page by definition. The site root keeps
     * its slash ("https://host/"), see withRootPath().
     *
     * @param HTTPRequest|null $request The request being answered; looked up from the current controller when
     *                                  omitted (template use).
     */
    public function SeoCanonicalURL(?HTTPRequest $request = null): ?string
    {
        $page = $this->getOwner();
        if (!$page->isInDB() || static::isErrorPage($page)) {
            return null;
        }
        $request ??= $this->currentRequest();

        $override = trim((string) $page->CanonicalURL);
        $source = $page instanceof VirtualPage ? $page->ContentSource() : null;
        $canonicalPage = $this->SeoCanonicalPage();
        if ($subject = $this->SeoSubjectMetadata()) {
            $url = $subject->canonicalURL();
        } elseif ($canonicalPage) {
            # The published page's link, without the ?stage= a draft reading mode adds. A virtual page copies
            # the pick from its source, so this also covers that case
            $url = strtok((string) $canonicalPage->AbsoluteLink(), '?') ?: null;
        } elseif ($override !== '' && static::isValidCanonicalInput($override)) {
            # A virtual page copies the override from its source, so this branch also covers that case
            $url = Director::absoluteURL($override) ?: null;
        } elseif ($source instanceof SiteTree && $source->isInDB() && $source->ID !== $page->ID) {
            $url = strtok((string) $source->AbsoluteLink(), '?') ?: null;
        } else {
            # In draft the page link carries ?stage=Stage; the canonical is always the clean public URL
            $url = strtok((string) $page->AbsoluteLink(), '?') ?: null;
            if ($url && $request) {
                $actionPath = $this->requestActionPath($request);
                if ($actionPath !== '') {
                    # Built by the page itself, with the action: the homepage's link is the site root, and only
                    # RelativeLink($action) keeps its "home" segment
                    $url = strtok((string) $page->AbsoluteLink($actionPath), '?') ?: $url;
                }
                $kept = array_intersect_key(
                    $request->getVars(),
                    array_flip((array) $page->config()->get('seo_canonical_query_params'))
                );
                if ($kept) {
                    $url .= '?' . http_build_query($kept);
                }
            }
        }

        if ($url) {
            $url = static::withRootPath($url);
        }

        $page->extend('updateSeoCanonicalURL', $url, $request);
        return $url ?: null;
    }

    /**
     * The picked canonical page (CanonicalPage) as published, or null: none picked, the page itself, or one that
     * is not published (any more) or no longer exists. Then the page falls back to the canonical it would have
     * without it, and the canonical check reports the pick ({@see canonicalPageState()}).
     */
    public function SeoCanonicalPage(): ?SiteTree
    {
        $page = $this->getOwner();
        $id = (int) $page->CanonicalPageID;
        if ($id <= 0 || $id === (int) $page->ID) {
            return null;
        }
        $live = Versioned::get_by_stage(SiteTree::class, Versioned::LIVE)->byID($id);
        return $live instanceof SiteTree ? $live : null;
    }

    /**
     * What became of the picked canonical page: '' when none is picked (or the page itself), 'published',
     * 'unpublished' (a draft only) or 'missing' (archived or deleted). The draft record is returned along for
     * an unpublished one, so a message can name it.
     *
     * @return array{0: string, 1: ?SiteTree}
     */
    public function canonicalPageState(): array
    {
        $page = $this->getOwner();
        $id = (int) $page->CanonicalPageID;
        if ($id <= 0 || $id === (int) $page->ID) {
            return ['', null];
        }
        if ($live = $this->SeoCanonicalPage()) {
            return ['published', $live];
        }
        $draft = Versioned::get_by_stage(SiteTree::class, Versioned::DRAFT)->byID($id);
        return $draft instanceof SiteTree ? ['unpublished', $draft] : ['missing', null];
    }

    /**
     * The URL with "/" as its path when it has none: the site root as "https://host/", not "https://host".
     * Director::absoluteURL() leaves the root without a path when `add_trailing_slash` is off (the default), for
     * the homepage's link and for an override on the site's own host alike. Both forms are the same URL (RFC 3986
     * 6.2.3), but search engines and SEO tools show the root with its slash. Any other path is left as it is.
     * Public for the structured data, whose URLs and `@id`s follow the canonical.
     */
    public static function withRootPath(string $url): string
    {
        # scheme://authority followed directly by a query, a fragment or the end
        return (string) preg_replace('~^([a-z][a-z0-9+.\-]*://[^/?#]+)(?=[?#]|$)~i', '$1/', $url);
    }

    /**
     * The Open Graph description: its own field, then the meta description chain.
     */
    public function SeoOGDescription(): string
    {
        if ($subject = $this->SeoSubjectMetadata()) {
            return $subject->sharingDescription();
        }
        $own = trim((string) $this->getOwner()->SEOFBdescription);
        return $own !== '' ? $own : $this->SeoDescription();
    }

    public function SeoOGType(): string
    {
        return (string) $this->getOwner()->config()->get('seo_og_type') ?: 'website';
    }

    /**
     * The sharing image: the page's own, then the first usable image of `seo_image_fallback`, then whatever the
     * `updateSeoImageFallback` hook makes of that (it receives the config's image, or null, and may replace it:
     * a project's hero image, say), then the site default from SiteConfig. Null when there is none at all. For a shown record
     * the record's own image takes the page image's place: a record without one goes to the hook and the site
     * default, not to the image of the page that lists it.
     */
    public function SeoImage(): ?Image
    {
        $page = $this->getOwner();
        $subject = $this->SeoSubjectMetadata();
        if ($subject) {
            $subjectImage = $subject->image();
            if (static::isUsableImage($subjectImage)) {
                return $subjectImage;
            }
            # The holder page's own image illustrates the listing, not this record: skip it, go to the hook
        } else {
            $image = $page->OGImage();
            if (static::isUsableImage($image)) {
                return $image;
            }
        }

        # The page's relations illustrate the page, not a record it shows: only for the page itself
        $image = $subject ? null : $this->configuredFallbackImage();
        $page->extend('updateSeoImageFallback', $image);
        if (static::isUsableImage($image)) {
            return $image;
        }

        $default = SiteConfig::current_site_config()->SeoDefaultImage();
        return static::isUsableImage($default) ? $default : null;
    }

    /**
     * The first usable image of `seo_image_fallback`, or null.
     */
    protected function configuredFallbackImage(): ?Image
    {
        $page = $this->getOwner();
        foreach ((array) $page->config()->get('seo_image_fallback') as $name) {
            $name = (string) $name;
            # Only what the page has: a relation (magic method) or a method of its own; anything else is skipped
            if ($name === '' || !$page->hasMethod($name)) {
                continue;
            }
            $value = $page->$name();
            $candidates = $value instanceof SS_List ? $value->limit(10) : [$value];
            foreach ($candidates as $candidate) {
                if (static::isUsableImage($candidate)) {
                    return $candidate;
                }
            }
        }
        return null;
    }

    /**
     * The structured data of this page view: for the record the controller shows, when it declared one, else for
     * the page itself.
     */
    public function SeoSchema(): PageSchema
    {
        return PageSchema::create($this->getOwner(), $this->SeoSubjectMetadata()?->getRecord());
    }

    /**
     * Missing required properties (the node is then left out) and missing recommended ones, for every node the
     * page view would output. See {@see PageSchema::getWarnings()} for the shape of an entry.
     *
     * @return array[]
     */
    public function getSchemaWarnings(): array
    {
        return $this->SeoSchema()->getWarnings();
    }

    # --- Head output ---------------------------------------------------------------------------------------------

    /**
     * Core's SS6 hook for the head tags. Keys are stable, so a project's own updateMetaComponents can find and
     * change any of these after this extension ran.
     */
    protected function updateMetaComponents(array &$tags): void
    {
        $page = $this->getOwner();

        # Core puts the plain page title here; HTML::createTag does not escape content, so escape it ourselves
        if (isset($tags['title'])) {
            $tags['title']['content'] = Convert::raw2xml($this->SeoTitle());
        }

        $description = $this->SeoDescription();
        if ($description !== '') {
            $tags['description'] = ['attributes' => ['name' => 'description', 'content' => $description]];
        }

        $robots = $this->SeoRobots();
        if ($robots !== '') {
            $tags['robots'] = ['attributes' => ['name' => 'robots', 'content' => $robots]];
        }

        # An error status set by the action (a 410 for an expired offer, say) means this URL has no canonical
        $status = $this->currentController()?->getResponse()->getStatusCode() ?? 200;
        $canonical = $status < 400 ? $this->SeoCanonicalURL() : null;
        if ($canonical && $page->config()->get('seo_output_canonical')) {
            $tags['canonical'] = ['tag' => 'link', 'attributes' => ['rel' => 'canonical', 'href' => $canonical]];
        }

        if ($page->config()->get('seo_output_open_graph')) {
            foreach ($this->openGraphTags($canonical) as $key => $tag) {
                $tags[$key] = $tag;
            }
        }

        # Not on an error answer or an error page: the URL describes nothing
        if ($status < 400 && $page->config()->get('seo_output_structured_data')
            && $page->isInDB() && !static::isErrorPage($page)
        ) {
            try {
                $json = $this->SeoSchema()->getGraph()->toJson();
            } catch (Throwable $e) {
                # A source that throws (a corrupt API cache, a bug in a project's provideSchemaData) costs the
                # structured data, never the page
                $json = '';
                static::logger()?->warning(sprintf(
                    'Structured data left out of %s: %s',
                    $page->Link(),
                    $e->getMessage()
                ), ['exception' => $e]);
            }
            if ($json !== '') {
                # HTML::createTag does not escape content; the JSON is made safe for a script element by
                # SchemaGraph (JSON_HEX_TAG), so it goes in as it is
                $tags['structured-data'] = [
                    'tag' => 'script',
                    'attributes' => ['type' => 'application/ld+json'],
                    'content' => $json,
                ];
            }
        }

        $page->extend('updateSeoMetaComponents', $tags);
    }

    /**
     * @return array<string,array> Meta components keyed by property name
     */
    protected function openGraphTags(?string $canonical): array
    {
        $og = [
            'og:title' => $this->SeoPageTitle(),
            'og:description' => $this->SeoOGDescription(),
            'og:type' => $this->SeoOGType(),
            'og:url' => (string) $canonical,
            'og:site_name' => (string) SiteConfig::current_site_config()->Title,
        ];

        $image = $this->SeoImage();
        $size = (array) $this->getOwner()->config()->get('seo_og_image_size');
        if ($image && !empty($size['width']) && !empty($size['height'])) {
            # Fill crops to the exact ratio; null when the variant cannot be made, then the original is used
            $image = $image->Fill((int) $size['width'], (int) $size['height']) ?: $image;
        }
        $imageUrl = $image ? (string) $image->getAbsoluteURL() : '';
        if ($imageUrl !== '') {
            $og['og:image'] = $imageUrl;
            $og['og:image:width'] = (string) ($image->getWidth() ?: '');
            $og['og:image:height'] = (string) ($image->getHeight() ?: '');
        }

        $tags = [];
        foreach ($og as $property => $content) {
            if ($content !== '') {
                $tags[$property] = ['attributes' => ['property' => $property, 'content' => $content]];
            }
        }

        $card = (string) $this->getOwner()->config()->get('seo_twitter_card');
        if ($card !== '') {
            # summary_large_image without an image renders as an empty box on X
            $card = $imageUrl !== '' ? $card : 'summary';
            $tags['twitter:card'] = ['attributes' => ['name' => 'twitter:card', 'content' => $card]];
        }

        return $tags;
    }

    # --- Internals -----------------------------------------------------------------------------------------------

    protected function descriptionFallback(): string
    {
        $page = $this->getOwner();
        if (!$page->config()->get('seo_description_fallback')) {
            return '';
        }
        # The page class's own fallback first (a description generated from course data, say), by convention
        # a MetaDescriptionFallback() method; empty leaves it to the content summary
        $description = $page->hasMethod('MetaDescriptionFallback')
            ? trim((string) $page->MetaDescriptionFallback())
            : '';
        if ($description === '') {
            $description = DescriptionSummary::fromHtml(
                (string) $page->Content,
                (int) $page->config()->get('seo_description_fallback_length')
            );
        }
        $page->extend('updateSeoDescriptionFallback', $description);
        return trim((string) $description);
    }

    /**
     * Whether the page's title for search is not what the MetaTitle field holds: the page class overrides
     * getMetaTitle(), or does not let editors change the field (seo_hidden_fields, seo_readonly_fields). The
     * search result preview then shows the title from PHP rather than build it from the form.
     */
    public function SeoTitleFromCode(): bool
    {
        $page = $this->getOwner();
        if ($page->hasMethod('getMetaTitle')) {
            return true;
        }
        $locked = array_merge(
            (array) $page->config()->get('seo_hidden_fields'),
            (array) $page->config()->get('seo_readonly_fields')
        );
        return in_array('MetaTitle', $locked, true);
    }

    /**
     * Whether the title is the site name, or already ends with it after a separator ("Courses | Site",
     * "Courses - Site"). The site name merely ending a word ("Workshop" on a site called "Shop") does not count.
     */
    protected static function endsWithSiteName(string $title, string $suffix): bool
    {
        if (mb_strtolower($title) === mb_strtolower($suffix)) {
            return true;
        }
        # A separator is anything that is neither a letter, a digit nor a space (|, -, :, a dash or a bullet),
        # with optional spaces around it
        return (bool) preg_match('/[^\p{L}\p{N}\s]\s*' . preg_quote($suffix, '/') . '$/iu', $title);
    }

    /**
     * SiteConfig.SeoTitleSuffix when set, else the site name.
     */
    protected function titleSuffix(): string
    {
        $config = SiteConfig::current_site_config();
        return trim((string) ($config->SeoTitleSuffix ?: $config->Title));
    }

    /**
     * The record the current controller declared as the subject of this page view, wrapped for reading; null on
     * the page itself.
     */
    public function SeoSubjectMetadata(): ?SubjectMetadata
    {
        $controller = $this->currentController();
        $record = $controller && $controller->hasMethod('getSeoSubject') ? $controller->getSeoSubject() : null;
        return $record instanceof DataObject ? SubjectMetadata::create($record, $this->getOwner()) : null;
    }

    /**
     * The PSR-3 logger, if the Injector has one.
     */
    protected static function logger(): ?LoggerInterface
    {
        try {
            return Injector::inst()->get(LoggerInterface::class);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * The controller currently rendering this very page, if any.
     */
    protected function currentController(): ?ContentController
    {
        # SS6: curr() returns null when no controller is on the stack (SS5's has_curr() is gone)
        $controller = Controller::curr();
        if ($controller instanceof ContentController && (int) $controller->data()?->ID === (int) $this->getOwner()->ID) {
            return $controller;
        }
        return null;
    }

    /**
     * The request of the controller currently rendering this very page, if any. Used when a template calls
     * $SeoCanonicalURL without arguments, so action URLs still get their own canonical.
     */
    protected function currentRequest(): ?HTTPRequest
    {
        return $this->currentController()?->getRequest();
    }

    /**
     * The part of the requested URL after the page's own path (`archive/2024/05/12` on /blog/archive/2024/05/12),
     * with the action segment lowercased; an empty string for the page itself or its index action.
     *
     * The URL is used rather than the Action/ID/OtherID parameters, because a url_handler can match more segments
     * than those three. RequestHandler answers a URL it did not fully parse with a 404, so whatever is left after
     * the page's path on a rendered page was matched by the action. A request that does not start with the page's
     * path (a custom Director rule) falls back to the three parameters.
     */
    protected function requestActionPath(HTTPRequest $request): string
    {
        $split = fn (string $path): array => array_values(array_filter(
            explode('/', trim($path, '/')),
            fn ($part) => $part !== ''
        ));
        # RelativeLink(true) keeps the homepage's own segment ("home"), which the request URL carries as well:
        # RootURLController rewrites a request for the site root to the homepage's segment
        $pageParts = $split((string) strtok((string) $this->getOwner()->RelativeLink(true), '?'));
        $requestParts = $split((string) $request->getURL());

        $normalise = fn (array $parts): array => array_map(fn ($part) => mb_strtolower(rawurldecode($part)), $parts);
        if ($normalise(array_slice($requestParts, 0, count($pageParts))) === $normalise($pageParts)) {
            $parts = array_slice($requestParts, count($pageParts));
        } else {
            $parts = $split(Controller::join_links(
                (string) $request->param('Action'),
                (string) $request->param('ID'),
                (string) $request->param('OtherID')
            ));
        }
        if (!$parts) {
            return '';
        }

        # Actions match case-insensitively (RequestHandler::hasAction), so /page/DETAIL and /page/detail are one
        # page; the segments after the action are values (IDs, slugs) and keep their case
        $parts[0] = strtolower($parts[0]);
        if ($parts === ['index']) {
            return '';
        }
        return implode('/', $parts);
    }

    /**
     * An ErrorPage, when silverstripe/errorpage is installed (the module does not require it). Checked by class
     * rather than by status: ErrorPageController sets the error status only after the page has been rendered.
     */
    protected static function isErrorPage(SiteTree $page): bool
    {
        $class = 'SilverStripe\\ErrorPage\\ErrorPage';
        return class_exists($class) && $page instanceof $class;
    }

    /**
     * An image a crawler can fetch: it exists, is a raster image, and is public. A sharing image is fetched by
     * the sites it is shared on, anonymously, so an image in the protected store (a draft-only file, or one with
     * view restrictions) would answer them with an error.
     */
    public static function isUsableImage(mixed $image): bool
    {
        if (!$image instanceof Image || !$image->exists() || !$image->getIsImage()) {
            return false;
        }
        # Draft-only: exists() also finds files in the protected store, so this is checked separately
        if ($image->hasExtension(Versioned::class) && !$image->isPublished()) {
            return false;
        }
        # Where the bytes are, and whether an anonymous visitor may view the record. AssetControlExtension keeps
        # the two in step when a file is written or published (it protects a file whose canView() fails for an
        # anonymous visitor); both are checked, as the store and the permissions are separate systems
        if ($image->getVisibility() !== AssetStore::VISIBILITY_PUBLIC) {
            return false;
        }
        return (bool) Member::actAs(null, fn () => $image->canView());
    }
}
