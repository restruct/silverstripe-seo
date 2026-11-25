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
