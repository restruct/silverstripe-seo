<?php

namespace Restruct\Silverstripe\SEO\Model;

use SilverStripe\Assets\Image;

/**
 * A record that supplies its own search and sharing metadata when a page shows it on a sub-action (a course on
 * /courses/item/12, a vacancy, an event). The page controller declares the record with `setSeoSubject()`.
 *
 * Implementing this is optional. A record without it is read by duck-typing instead: MetaTitle or Title,
 * MetaDescription or the first paragraph of Content, AbsoluteLink() or Link(), and an `OGImage` has_one.
 * Implement it when those names do not fit, or to set robots directives per record.
 */
interface SeoSubject
{
    /**
     * The page title for search and sharing, without the site name (the module appends that).
     */
    public function getSeoTitle(): string;

    /**
     * Plain text; an empty string leaves the description out.
     */
    public function getSeoDescription(): string;

    /**
     * The absolute canonical URL of the record, or null to leave the canonical out.
     */
    public function getSeoCanonicalURL(): ?string;

    /**
     * The sharing image, or null to fall back to the `updateSeoImageFallback` hook and then the site default
     * (not to the image of the page showing the record).
     */
    public function getSeoImage(): ?Image;

    /**
     * Robots directives such as ['noindex']; an empty array means indexable, null means "same as the page".
     *
     * @return string[]|null
     */
    public function getSeoRobotsDirectives(): ?array;
}
