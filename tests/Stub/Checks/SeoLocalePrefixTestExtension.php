<?php

namespace Restruct\Silverstripe\SEO\Tests\Stub\Checks;

use Restruct\Silverstripe\SEO\Checks\LinkResolver;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Core\Extension;
use SilverStripe\Dev\TestOnly;

/**
 * What a translation-module bridge would do: a URL with a locale prefix (/nl/...) is resolved without it.
 *
 * @extends Extension<LinkResolver>
 */
class SeoLocalePrefixTestExtension extends Extension implements TestOnly
{
    protected function updateResolveInternalLink(array &$result, string $url, string $relative): void
    {
        if ($result['type'] === LinkResolver::MISSING_PAGE && str_starts_with($relative, 'nl/')) {
            $page = SiteTree::get_by_link(substr($relative, 3));
            if ($page) {
                $result = ['type' => LinkResolver::PAGE, 'record' => $page, 'path' => $relative];
            }
        }
    }
}
