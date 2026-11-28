<?php

namespace Restruct\Silverstripe\SEO\Tests\Stub;

use SilverStripe\Control\HTTPRequest;
use SilverStripe\Core\Extension;
use SilverStripe\Dev\TestOnly;

/**
 * The project hook on the canonical, inert unless a test sets a suffix: it then appends it to whatever canonical
 * the module worked out, so a test sees that the hook ran after the module's own choice.
 */
class SeoCanonicalHookTestExtension extends Extension implements TestOnly
{
    public static ?string $suffix = null;

    protected function updateSeoCanonicalURL(?string &$url, ?HTTPRequest $request): void
    {
        if (static::$suffix !== null && $url) {
            $url .= static::$suffix;
        }
    }
}
