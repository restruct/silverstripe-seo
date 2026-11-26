<?php

namespace Restruct\Silverstripe\SEO\Model;

use Restruct\Silverstripe\SEO\Text\DescriptionSummary;
use SilverStripe\Assets\Image;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Control\Director;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\ORM\DataObject;

/**
 * Reads search and sharing metadata from the record a page is showing (its SEO subject): through the
 * {@see SeoSubject} interface when the record implements it, otherwise by duck-typing on common field and
 * method names, so existing DataObjects work without changes.
 *
 * Settings (fallback length, robots columns, tracking parameters) are taken from the page showing the record,
 * so one YAML block configures pages and their records alike.
 */
class SubjectMetadata
{
    # Injectable, so a project can swap in its own reader through the Injector
    use Injectable;

    public function __construct(
        protected DataObject $record,
        protected SiteTree $page
    ) {
    }

    public function getRecord(): DataObject
    {
        return $this->record;
    }

    public function title(): string
    {
        if ($this->record instanceof SeoSubject) {
            return trim($this->record->getSeoTitle());
        }
        return trim((string) ($this->value('MetaTitle') ?: $this->value('Title')));
    }

    public function description(): string
    {
        if ($this->record instanceof SeoSubject) {
            return trim($this->record->getSeoDescription());
        }
        $typed = trim((string) $this->value('MetaDescription'));
        if ($typed !== '' || !$this->page->config()->get('seo_description_fallback')) {
            return $typed;
        }
        $description = DescriptionSummary::fromHtml(
            (string) $this->value('Content'),
            (int) $this->page->config()->get('seo_description_fallback_length')
        );
        # Same hook name as on pages, called on the record, so a record class can supply its own fallback
        $this->record->extend('updateSeoDescriptionFallback', $description);
        return trim((string) $description);
    }

    /**
     * The sharing description: an own SEOFBdescription field when the record has one, then its description.
     */
    public function sharingDescription(): string
    {
        $own = trim((string) $this->value('SEOFBdescription'));
        return $own !== '' ? $own : $this->description();
    }

    /**
     * The record's own absolute link with tracking parameters removed. Other parameters stay: they are part of
     * the record's link, so the record (not this module) decides they make a different page.
     */
    public function canonicalURL(): ?string
    {
        if ($this->record instanceof SeoSubject) {
            $url = $this->record->getSeoCanonicalURL();
        } elseif ($this->record->hasMethod('AbsoluteLink')) {
            $url = $this->record->AbsoluteLink();
        } elseif ($this->record->hasMethod('Link')) {
            $url = Director::absoluteURL((string) $this->record->Link());
        } else {
            $url = null;
        }
        return $url ? static::stripTrackingParameters(
            (string) $url,
            (array) $this->page->config()->get('seo_tracking_query_params')
        ) : null;
    }

    public function image(): ?Image
    {
        if ($this->record instanceof SeoSubject) {
            return $this->record->getSeoImage();
        }
        if ($this->record->hasMethod('getSeoImage')) {
            $image = $this->record->getSeoImage();
            return $image instanceof Image ? $image : null;
        }
        # A has_one called OGImage, as on pages
        if ($this->record->getRelationType('OGImage') === 'has_one') {
            $image = $this->record->getComponent('OGImage');
            return $image instanceof Image ? $image : null;
        }
        return null;
    }

    /**
     * @return string[]|null Null when the record has no robots settings of its own, so the page's apply
     */
    public function robotsDirectives(): ?array
    {
        if ($this->record instanceof SeoSubject) {
            return $this->record->getSeoRobotsDirectives();
        }
        $map = (array) $this->page->config()->get('seo_robots_directives');
        $hasAny = false;
        $directives = [];
        foreach ($map as $field => $directive) {
            # A column or a getter of that name is a robots setting of the record's own
            if (!$this->record->hasField($field) && !$this->hasGetter($field)) {
                continue;
            }
            $hasAny = true;
            if ($directive && $this->value($field)) {
                $directives[] = $directive;
            }
        }
        return $hasAny ? $directives : null;
    }

    /**
     * Removes query parameters whose name matches one of the patterns (shell wildcards, e.g. `utm_*`).
     *
     * @param string[] $patterns
     */
    public static function stripTrackingParameters(string $url, array $patterns): string
    {
        $parts = explode('?', $url, 2);
        if (count($parts) < 2 || !$patterns) {
            return $url;
        }
        [$base, $query] = $parts;
        # Keep a fragment, if any, at the end
        $fragment = '';
        if (str_contains($query, '#')) {
            [$query, $fragment] = explode('#', $query, 2);
            $fragment = '#' . $fragment;
        }

        parse_str($query, $params);
        foreach (array_keys($params) as $name) {
            foreach ($patterns as $pattern) {
                if (fnmatch(strtolower((string) $pattern), strtolower((string) $name))) {
                    unset($params[$name]);
                    break;
                }
            }
        }
        return $base . ($params ? '?' . http_build_query($params) : '') . $fragment;
    }

    /**
     * A value read as a template reads it: a `get<Name>()` getter (an override that ignores the column
     * included), else the field, else a method of that name; null when the record has none of them.
     */
    protected function value(string $name): mixed
    {
        if ($this->hasGetter($name) || $this->record->hasField($name)) {
            # ModelData::__get: the getter first, then the field
            return $this->record->__get($name);
        }
        if ($this->record->hasMethod($name)) {
            return $this->record->$name();
        }
        return null;
    }

    /**
     * Whether the record has a `get<Name>()` getter. getField() is no getter of a field called "Field"
     * (ModelData::__get makes the same exception).
     */
    protected function hasGetter(string $name): bool
    {
        return strtolower($name) !== 'field' && $this->record->hasMethod('get' . $name);
    }
}
