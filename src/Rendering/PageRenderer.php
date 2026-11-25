<?php

namespace Restruct\Silverstripe\SEO\Rendering;

use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Security\Member;

/**
 * Renders a page in-process, through its own controller and templates, as a given member and in a given stage.
 *
 * Not implemented yet: the page checks of a later version use it to analyse the page as the site renders it
 * (Elemental blocks and template text included) rather than reading CMS fields. It is an interface so that a
 * shared renderer (for example one used by restruct/silverstripe-ai as well) can be swapped in through the
 * Injector without this module depending on it.
 *
 * An implementation must leave no trace in the request it runs inside: themes, the Requirements backend, the
 * default reading mode, the current page, cache-control state and the session are all restored afterwards.
 */
interface PageRenderer
{
    /**
     * @param string $stage Versioned::DRAFT or Versioned::LIVE
     * @param Member|null $member Who to render as; null for an anonymous visitor ("as a search engine sees it")
     * @return array{status:int, body:string, headers:array<string,string>} The response as rendered, headers
     *         included, so X-Robots-Tag and Link can be checked as well as the head
     */
    public function render(SiteTree $page, string $stage, ?Member $member = null): array;
}
