<?php

namespace Restruct\Silverstripe\SEO\Tests\Stub\Checks;

use SilverStripe\CMS\Controllers\ContentController;
use SilverStripe\Dev\TestOnly;

/**
 * Controller for {@see SeoCheckTestPage}.
 */
class SeoCheckTestPageController extends ContentController implements TestOnly
{
    protected function init()
    {
        parent::init();
        if ($this->redirectedTo()) {
            return;
        }
        $target = (string) $this->dataRecord?->RedirectTo;
        if ($target === 'self') {
            # As a page that sends itself to its own canonical address would (a slash, a locale prefix)
            $this->redirect($this->dataRecord->AbsoluteLink(), 301);
        } elseif ($target !== '') {
            $this->redirect($target, 302);
        }
    }
}
