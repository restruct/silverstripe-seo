<?php

namespace Restruct\Silverstripe\SEO\Tests\Stub;

use SilverStripe\Assets\Image;
use SilverStripe\Core\Extension;
use SilverStripe\Dev\TestOnly;

/**
 * Exercises the two fallback hooks the module offers to projects. Each value is a public static so a test can
 * switch the hook on for one case and leave it inert for the rest.
 */
class SeoFallbackTestExtension extends Extension implements TestOnly
{
    public static ?Image $image = null;

    public static ?string $description = null;

    protected function updateSeoImageFallback(?Image &$image): void
    {
        if (static::$image) {
            $image = static::$image;
        }
    }

    protected function updateSeoDescriptionFallback(?string &$description): void
    {
        if (static::$description !== null) {
            $description = static::$description;
        }
    }
}
