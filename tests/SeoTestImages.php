<?php

namespace Restruct\Silverstripe\SEO\Tests;

use SilverStripe\Assets\Image;

/**
 * Builds real raster images for the tests with GD, so image variants (the Open Graph resize) actually run.
 * GD is used on purpose: it is a framework requirement already, and no SVG or external converter is involved.
 */
trait SeoTestImages
{
    protected function createTestImage(string $filename, int $width = 1600, int $height = 900): Image
    {
        $gd = imagecreatetruecolor($width, $height);
        imagefill($gd, 0, 0, imagecolorallocate($gd, 40, 120, 200));
        ob_start();
        imagepng($gd);
        $bytes = (string) ob_get_clean();
        imagedestroy($gd);

        $image = Image::create();
        $image->setFromString($bytes, 'seo-tests/' . $filename);
        $image->write();
        # Published, because a social image must be publicly reachable to be of any use
        $image->publishSingle();
        return $image;
    }
}
