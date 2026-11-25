<?php

namespace Restruct\Silverstripe\SEO\Tests\Stub\Schema;

use Restruct\Silverstripe\SEO\Tests\Stub\SeoTestPageController;
use SilverStripe\Control\HTTPRequest;

/**
 * Shows one event of the holder and makes it the SEO subject, so the event becomes the page's main entity.
 */
class SchemaTestHolderPageController extends SeoTestPageController
{
    private static array $allowed_actions = [
        'event',
    ];

    public function event(HTTPRequest $request)
    {
        $event = SchemaTestEvent::get()->byID((int) $request->param('ID'));
        if (!$event) {
            return $this->httpError(404);
        }
        $this->setSeoSubject($event);
        return $this;
    }
}
