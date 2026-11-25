<?php

namespace Restruct\Silverstripe\SEO\Extensions;

use SilverStripe\CMS\Controllers\ContentController;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Core\Extension;
use SilverStripe\ORM\DataObject;

/**
 * Sends the page's robots directives as `X-Robots-Tag` and its canonical URL as a `Link: <...>; rel="canonical"`
 * header, and lets an action declare the record it shows as the SEO subject.
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
     * Declare the record this request shows (a holder's /item/12 action), so the head and the headers describe
     * that record rather than the holder page. Call it before rendering. Pass null to go back to the page.
     *
     * A controller can instead define its own `getSeoSubject()`, which then takes precedence over this one.
     */
    public function setSeoSubject(?DataObject $record): ContentController
    {
        # Stored on the controller, not on this extension: extension instances can be shared between owners
        return $this->getOwner()->setDynamicData('SeoSubject', $record);
    }

    public function getSeoSubject(): ?DataObject
    {
        $owner = $this->getOwner();
        $record = $owner->hasDynamicData('SeoSubject') ? $owner->getDynamicData('SeoSubject') : null;
        return $record instanceof DataObject ? $record : null;
    }

    /**
     * After init, the controller holds the response that a normal (template-rendered) action will fill.
     */
    protected function onAfterInit(): void
    {
        $this->addSeoHeaders($this->getOwner()->getResponse(), $this->getOwner()->getRequest());
    }

    /**
     * Again after the action ran: the action may have declared a subject (other robots, another canonical), or
     * returned its own HTTPResponse, which replaces the one from init (Controller::prepareResponse).
     */
    protected function afterCallActionHandler(HTTPRequest $request, $action, $result): void
    {
        $response = $result instanceof HTTPResponse ? $result : $this->getOwner()->getResponse();
        $this->addSeoHeaders($response, $request);
    }

    protected function addSeoHeaders(HTTPResponse $response, HTTPRequest $request): void
    {
        $page = $this->getOwner()->data();
        if (!$page instanceof SiteTree || !$page->hasExtension(SeoSiteTreeExtension::class) || !$page->isInDB()) {
            return;
        }

        # A redirect (a RedirectorPage included) has no content of its own: the target answers for itself, so
        # neither header goes on it. setHeader() also removes a value this extension set during init.
        $status = $response->getStatusCode();
        $isRedirect = $status >= 300 && $status < 400;
        $this->setHeader($response, 'X-Robots-Tag', $isRedirect ? '' : $page->SeoRobots());

        # Only on a normal answer: a redirect or an error page has no canonical of its own
        $canonical = $page->SeoCanonicalURL($request);
        $link = '';
        if ($canonical && $status < 300) {
            # A URL can contain neither < nor > unescaped, but an override is editor input: encode to be sure
            $link = '<' . str_replace(['<', '>'], ['%3C', '%3E'], $canonical) . '>; rel="canonical"';
        }
        $this->setHeader($response, 'Link', $link);
    }

    /**
     * Sets (or clears) a header this extension owns. A value set earlier by this extension is replaced or removed
     * when the action changed the outcome; a value someone else set is left alone.
     */
    protected function setHeader(HTTPResponse $response, string $name, string $value): void
    {
        $key = 'SeoHeaderSent_' . strtolower($name);
        $owner = $this->getOwner();
        $ours = $owner->hasDynamicData($key) ? $owner->getDynamicData($key) : null;
        $current = $response->getHeader($name);

        if ($current !== null && $current !== $ours) {
            # Not ours (the project or another module set it): never overwrite
            return;
        }
        if ($value === '') {
            if ($current !== null) {
                $response->removeHeader($name);
            }
        } else {
            $response->addHeader($name, $value);
        }
        $owner->setDynamicData($key, $value === '' ? null : $value);
    }
}
