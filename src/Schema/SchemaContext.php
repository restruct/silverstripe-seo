<?php

namespace Restruct\Silverstripe\SEO\Schema;

use Restruct\Silverstripe\SEO\Extensions\SeoSiteTreeExtension;
use Restruct\Silverstripe\SEO\Model\SubjectMetadata;
use SilverStripe\Assets\Image;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Control\Director;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\ORM\DataObject;

/**
 * What a structured-data source needs to know about the page being described: the page, the record it shows (if
 * any), the site's base URL, and how to build the `@id`s, dates and images the module uses everywhere.
 *
 * Passed to `provideSchemaData(SchemaContext $ctx)` and to the `updateSchemaData(array &$node, SchemaContext $ctx)`
 * hook.
 *
 * The `@id` rule:
 * - site nodes: `<base URL>/#website`, `<base URL>/#organization` ({@see siteId()});
 * - nodes of the page view: `<URL of the view>#webpage`, `#breadcrumb`, `#itemlist` ({@see pageId()}), where the
 *   URL of the view is its canonical URL (see pageURL()): the shown record's canonical when the page shows a
 *   record, else the page's canonical, with an action path and kept parameters;
 * - records: `<record link>#<type in lowercase>-<ID>`, or the page's link when the record has no link of its own
 *   ({@see recordId()}). Links are used without their query string, so a draft link (`?stage=Stage`) and a
 *   tracking parameter give the same id.
 */
class SchemaContext
{
    use Injectable;

    public function __construct(
        protected SiteTree $page,
        protected ?DataObject $subject = null
    ) {
    }

    public function getPage(): SiteTree
    {
        return $this->page;
    }

    /**
     * The record the page view shows (an event on /events/event/12), or null on the page itself.
     */
    public function getSubject(): ?DataObject
    {
        return $this->subject;
    }

    /**
     * The site's absolute base URL without a trailing slash.
     */
    public function baseURL(): string
    {
        return rtrim((string) Director::absoluteBaseURL(), '/');
    }

    /**
     * The `@id` of a site-wide node: `https://example.com/#organization`.
     */
    public function siteId(string $name): string
    {
        return $this->baseURL() . '/#' . $name;
    }

    /**
     * The URL of this page view: its canonical URL. For a shown record that is the record's canonical (tracking
     * parameters removed); for the page, SeoCanonicalURL(), so an action URL (`/about/detail`) and the
     * parameters in `seo_canonical_query_params` (`?page=2`) make a WebPage of their own, as they make a
     * canonical of their own. The page's link when there is no canonical (an unsaved page).
     */
    public function pageURL(): string
    {
        $url = null;
        if ($this->subject) {
            $url = SubjectMetadata::create($this->subject, $this->page)->canonicalURL();
        } elseif ($this->page->hasMethod('SeoCanonicalURL')) {
            $url = $this->page->SeoCanonicalURL();
        }
        if (!is_string($url) || $url === '') {
            return static::withoutQuery((string) $this->page->AbsoluteLink());
        }
        # The fragment is where the node name goes
        return (string) strtok($url, '#');
    }

    /**
     * The `@id` of a node of this page view: `https://example.com/about#webpage`.
     */
    public function pageId(string $fragment): string
    {
        return $this->pageURL() . '#' . $fragment;
    }

    /**
     * The record's own absolute link without query string, or the page's link when it has none.
     */
    public function recordURL(DataObject $record): string
    {
        $url = null;
        if ($record->hasMethod('AbsoluteLink')) {
            $url = $record->AbsoluteLink();
        } elseif ($record->hasMethod('Link')) {
            $link = $record->Link();
            $url = $link ? Director::absoluteURL((string) $link) : null;
        }
        if (!is_string($url) || $url === '') {
            $url = (string) $this->page->AbsoluteLink();
        }
        return static::withoutQuery($url);
    }

    /**
     * The `@id` of a record's node: `https://example.com/events/event/12#event-12`.
     */
    public function recordId(DataObject $record, string $type): string
    {
        $slug = preg_replace('/[^a-z0-9]+/', '', strtolower($type)) ?: 'thing';
        return $this->recordURL($record) . '#' . $slug . '-' . $record->ID;
    }

    /**
     * The configured timezone name (`seo_schema_timezone` on the page class); empty means PHP's default.
     */
    public function timezone(): string
    {
        return (string) $this->page->config()->get('seo_schema_timezone');
    }

    /**
     * {@see SchemaDates::format()} with the site timezone.
     */
    public function formatDate(mixed $value): ?string
    {
        return SchemaDates::format($value, $this->timezone());
    }

    /**
     * {@see SchemaDates::combine()} with the site timezone.
     */
    public function combineDateTime(mixed $date, mixed $time): ?string
    {
        return SchemaDates::combine($date, $time, $this->timezone());
    }

    /**
     * An ImageObject node for an image a crawler can fetch (published, public, a raster image), else null.
     */
    public function imageObject(mixed $image): ?array
    {
        if (!$image instanceof Image || !SeoSiteTreeExtension::isUsableImage($image)) {
            return null;
        }
        $url = (string) $image->getAbsoluteURL();
        if ($url === '') {
            return null;
        }
        return array_filter([
            '@type' => 'ImageObject',
            'url' => $url,
            'contentUrl' => $url,
            'width' => (int) $image->getWidth() ?: null,
            'height' => (int) $image->getHeight() ?: null,
        ], fn ($value) => $value !== null);
    }

    protected static function withoutQuery(string $url): string
    {
        $cut = strcspn($url, '?#');
        return substr($url, 0, $cut);
    }
}
