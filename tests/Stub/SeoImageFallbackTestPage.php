<?php

namespace Restruct\Silverstripe\SEO\Tests\Stub;

use SilverStripe\Assets\File;
use SilverStripe\Assets\Image;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Dev\TestOnly;
use SilverStripe\ORM\ManyManyList;

/**
 * A page with the kinds of image a project names in `seo_image_fallback`: a has_one image, a many_many gallery,
 * a method returning an image, and a has_one file that is not an image.
 *
 * @method Image HeroImage()
 * @method File Attachment()
 * @method ManyManyList<Image> Gallery()
 */
class SeoImageFallbackTestPage extends SiteTree implements TestOnly
{
    private static string $table_name = 'SeoImageFallbackTestPage';

    private static array $has_one = [
        'HeroImage' => Image::class,
        'Attachment' => File::class,
    ];

    private static array $many_many = [
        'Gallery' => Image::class,
    ];

    /**
     * Set by a test: what CardImage() returns.
     */
    public static ?Image $cardImage = null;

    public function CardImage(): ?Image
    {
        return static::$cardImage;
    }
}
