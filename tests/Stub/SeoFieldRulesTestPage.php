<?php

namespace Restruct\Silverstripe\SEO\Tests\Stub;

use SilverStripe\Dev\TestOnly;

/**
 * A page class that hides some of the module's fields and makes others read-only, as a page type whose title is
 * generated (a course page, say) would.
 */
class SeoFieldRulesTestPage extends SeoTestPage implements TestOnly
{
    private static string $table_name = 'SeoFieldRulesTestPage';

    private static array $seo_hidden_fields = [
        'MetaTitle',
        'OGImage',
    ];

    private static array $seo_readonly_fields = [
        'CanonicalURL',
        'MetaRobotsNoIndex',
        'MetaDescription',
    ];
}
