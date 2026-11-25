<?php

namespace Restruct\Silverstripe\SEO\Extensions;

use Restruct\Silverstripe\SEO\Model\SubjectMetadata;
use Restruct\Silverstripe\SEO\Text\DescriptionSummary;
use SilverStripe\AssetAdmin\Forms\UploadField;
use SilverStripe\Assets\Image;
use SilverStripe\CMS\Controllers\ContentController;
use SilverStripe\CMS\Model\RedirectorPage;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\CMS\Model\VirtualPage;
use SilverStripe\Control\Controller;
use SilverStripe\Control\Director;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Core\Convert;
use SilverStripe\Core\Extension;
use SilverStripe\Core\Validation\ValidationResult;
use SilverStripe\Forms\CheckboxField;
use SilverStripe\Forms\CompositeField;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\HeaderField;
use SilverStripe\Forms\ReadonlyTransformation;
use SilverStripe\Forms\TextareaField;
use SilverStripe\Forms\TextField;
use SilverStripe\ORM\DataObject;
use SilverStripe\SiteConfig\SiteConfig;

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
 * When the page's controller shows a record on a sub-action and declares it with `setSeoSubject()`, the title,
 * description, canonical, sharing values and (if the record has its own) robots come from that record instead;
 * see {@see SubjectMetadata}.
 *
 * Extension hooks offered to projects and later versions of this module, all called on the page:
 * - `updateSeoTitle(string &$title)`: the full `<title>` text, suffix included;
 * - `updateSeoDescriptionFallback(?string &$description)`: the description used when none was typed;
 * - `updateSeoRobotsDirectives(array &$directives)`: the robots directives, e.g. to add `max-image-preview`;
 * - `updateSeoCanonicalURL(?string &$url, ?HTTPRequest $request)`;
 * - `updateSeoImageFallback(?Image &$image)`: the sharing image when the page has none of its own;
 * - `updateSeoMetaComponents(array &$tags)`: every head tag this module produced, just before output. Structured
 *   data (a later version) is added at this point.
 *
 * @property string $MetaTitle
 * @property bool $MetaRobotsNoIndex
 * @property bool $MetaRobotsNoFollow
 * @property bool $MetaRobotsNoSnippet
 * @property bool $MetaRobotsNoCache
 * @property string $CanonicalURL
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
        'CanonicalURL',
        'SEOFBdescription',
        'OGImage',
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

    # --- CMS -----------------------------------------------------------------------------------------------------

    protected function updateCMSFields(FieldList $fields): void
    {
        $page = $this->getOwner();
        # A redirector page never renders, so robots, canonical and sharing settings would have no effect there
        if ($page instanceof RedirectorPage) {
            return;
        }

        /** @var CompositeField|null $metadata */
        $metadata = $fields->fieldByName('Root.Main.Metadata');
        if (!$metadata) {
            # A page class that removed core's toggle: fall back to a tab of our own rather than losing the fields
            $metadata = CompositeField::create()->setName('SeoFallbackGroup');
            $fields->addFieldToTab('Root.SEO', $metadata);
        }
        $metadata->setTitle(_t(__CLASS__ . '.ToggleTitle', 'Search engines and sharing'));

        $metadata->insertBefore(
            'MetaDescription',
            TextField::create('MetaTitle', $page->fieldLabel('MetaTitle'))
                ->setAttribute('placeholder', $page->Title)
                ->setDescription(_t(
                    __CLASS__ . '.MetaTitleHelp',
                    'Shown as the link text in search results. Leave empty to use the page title. The site name is added automatically.'
                ))
        );

        # Show editors the description search engines get when they leave the field empty
        $description = $metadata->getChildren()->dataFieldByName('MetaDescription');
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
            HeaderField::create('SeoRobotsHeader', _t(__CLASS__ . '.RobotsHeader', 'Search engine indexing'), 4),
            CheckboxField::create('MetaRobotsNoIndex', $page->fieldLabel('MetaRobotsNoIndex'))
                ->setDescription(_t(
                    __CLASS__ . '.NoIndexHelp',
                    'The page stays reachable, but asks search engines not to list it. It is also left out of the sitemap.'
                )),
            CheckboxField::create('MetaRobotsNoFollow', $page->fieldLabel('MetaRobotsNoFollow')),
            CheckboxField::create('MetaRobotsNoSnippet', $page->fieldLabel('MetaRobotsNoSnippet')),
            CheckboxField::create('MetaRobotsNoCache', $page->fieldLabel('MetaRobotsNoCache')),
            TextField::create('CanonicalURL', $page->fieldLabel('CanonicalURL'))
                ->setAttribute('placeholder', $page->isInDB() ? (string) $this->SeoCanonicalURL() : '')
                ->setDescription(_t(
                    __CLASS__ . '.CanonicalHelp',
                    'Only when this page duplicates another one: the URL of the original (https://... or a path starting with /). Leave empty and the page points to itself.'
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
            # Before core's free-form ExtraMeta, which stays last as the advanced option
            $metadata->insertBefore('ExtraMeta', $field);
        }

        if ($page instanceof VirtualPage) {
            $this->makeVirtualFieldsReadonly($page, $fields);
        }
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
                if ($directive && $page->getField($field)) {
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
     * at the page, since it shows different content. A shown record (SEO subject) gives its own link; a virtual
     * page points at the page it copies, since it is a duplicate of that page by definition.
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
        if ($subject = $this->SeoSubjectMetadata()) {
            $url = $subject->canonicalURL();
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

        $page->extend('updateSeoCanonicalURL', $url, $request);
        return $url ?: null;
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
     * The sharing image: the page's own, then whatever the `updateSeoImageFallback` hook provides (a project's
     * hero image, say), then the site default from SiteConfig. Null when there is none at all. For a shown record
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

        $image = null;
        $page->extend('updateSeoImageFallback', $image);
        if (static::isUsableImage($image)) {
            return $image;
        }

        $default = SiteConfig::current_site_config()->SeoDefaultImage();
        return static::isUsableImage($default) ? $default : null;
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
        $description = DescriptionSummary::fromHtml(
            (string) $page->Content,
            (int) $page->config()->get('seo_description_fallback_length')
        );
        $page->extend('updateSeoDescriptionFallback', $description);
        return trim((string) $description);
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

    protected static function isUsableImage(mixed $image): bool
    {
        return $image instanceof Image && $image->exists() && $image->getIsImage();
    }
}
