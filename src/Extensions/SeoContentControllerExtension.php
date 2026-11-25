<?php

namespace Restruct\Silverstripe\SEO\Extensions;

use SilverStripe\CMS\Controllers\ContentController;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Core\Extension;

/**
 * Sends the page's robots directives as `X-Robots-Tag` and its canonical URL as a `Link: <...>; rel="canonical"`
 * header.
 *
 * The headers go on the controller's response object, never through PHP's header(): that keeps them testable,
 * lets middleware (static caching, a CDN layer) see them, and lets code that renders a page in-process read them
 * back from the response.
 *
 * The headers matter beyond HTML: a crawler reads them for any response, including an action that returns a file
 * or JSON, where there is no `<head>` to carry a meta tag.
 *
 * @extends Extension<ContentController>
 */
class SeoContentControllerExtension extends Extension
{
    /**
     * After init, the controller holds the response that a normal (template-rendered) action will fill.
     */
    protected function onAfterInit(): void
    {
        $this->addSeoHeaders($this->getOwner()->getResponse(), $this->getOwner()->getRequest());
    }

    /**
     * An action that returns its own HTTPResponse replaces the one from init (Controller::prepareResponse), so
     * that object needs the headers as well.
     */
    protected function afterCallActionHandler(HTTPRequest $request, $action, $result): void
    {
        if ($result instanceof HTTPResponse) {
            $this->addSeoHeaders($result, $request);
        }
    }

    protected function addSeoHeaders(HTTPResponse $response, HTTPRequest $request): void
    {
        $page = $this->getOwner()->data();
        if (!$page instanceof SiteTree || !$page->hasExtension(SeoSiteTreeExtension::class) || !$page->isInDB()) {
            return;
        }

        $robots = $page->SeoRobots();
        if ($robots !== '') {
            $response->addHeader('X-Robots-Tag', $robots);
        }

        # Only on a normal answer: a redirect or an error page has no canonical of its own
        $canonical = $page->SeoCanonicalURL($request);
        if ($canonical && $response->getStatusCode() < 300) {
            # A URL can contain neither < nor > unescaped, but an override is editor input: encode to be sure
            $canonical = str_replace(['<', '>'], ['%3C', '%3E'], $canonical);
            $response->addHeader('Link', '<' . $canonical . '>; rel="canonical"');
        }
    }
}
