<?php

namespace Restruct\Silverstripe\SEO\Tests\Stub;

use SilverStripe\CMS\Controllers\ContentController;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Dev\TestOnly;

/**
 * Controller for {@see SeoTestPage}, with one extra action so the tests can check that an action URL gets its own
 * canonical instead of pointing back at the page.
 */
class SeoTestPageController extends ContentController implements TestOnly
{
    private static array $allowed_actions = [
        'detail',
        'download',
        'item',
        'subjectitem',
        'archive',
        'gone',
        'moved',
    ];

    /**
     * A url_handler with more segments than core's \$Action/\$ID/\$OtherID, as a blog archive has: the canonical
     * must keep all of them. The default handler still applies to the other actions (url_handlers are read per
     * class, this one first).
     */
    private static array $url_handlers = [
        'archive/$Year!/$Month/$Day' => 'archive',
    ];

    /**
     * An action rendered with the page template, as for example a blog tag or a listing filter would be.
     */
    public function detail()
    {
        return $this;
    }

    /**
     * An action that returns its own response object, as a file download or a JSON endpoint would: the headers
     * must reach that object too, not only the response the controller created during init.
     */
    public function download(): HTTPResponse
    {
        $response = HTTPResponse::create('file body', 200);
        $response->addHeader('Content-Type', 'text/plain');
        return $response;
    }

    /**
     * The action behind the archive url_handler, rendered with the page template.
     */
    public function archive()
    {
        return $this;
    }

    /**
     * An action that sets an error status on the response and still renders the page template, as a "this
     * offer has expired" page might.
     */
    public function gone()
    {
        $this->getResponse()->setStatusCode(410);
        return $this;
    }

    /**
     * An action that redirects, as an old URL kept for its inbound links would.
     */
    public function moved(): HTTPResponse
    {
        return $this->redirect($this->Link('detail'), 302);
    }

    /**
     * A holder page showing one record: the record becomes the SEO subject before the template renders.
     */
    public function item(HTTPRequest $request)
    {
        $item = SeoTestItem::get()->byID((int) $request->param('ID'));
        if (!$item) {
            return $this->httpError(404);
        }
        $this->setSeoSubject($item);
        return $this;
    }

    /**
     * Same, for a record implementing the SeoSubject interface.
     */
    public function subjectitem(HTTPRequest $request)
    {
        $item = SeoTestSubjectItem::get()->byID((int) $request->param('ID'));
        if (!$item) {
            return $this->httpError(404);
        }
        $this->setSeoSubject($item);
        return $this;
    }
}
