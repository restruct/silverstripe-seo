<?php

namespace Restruct\Silverstripe\SEO\Tests\Stub;

use SilverStripe\CMS\Controllers\ContentController;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Dev\TestOnly;

/**
 * Sets response headers the way project controllers do: in init() (before or after the parent's init) and in an
 * action, on the controller's response or on a response of its own. Which headers is set per test.
 */
class SeoHeaderTestPageController extends ContentController implements TestOnly
{
    /**
     * Headers set in init() before parent::init(), by name.
     *
     * @var array<string, string>
     */
    public static array $beforeParentInit = [];

    /**
     * Headers set in init() after parent::init(), by name.
     *
     * @var array<string, string>
     */
    public static array $afterParentInit = [];

    /**
     * Headers set by the actions, by name.
     *
     * @var array<string, string>
     */
    public static array $inAction = [];

    private static array $allowed_actions = [
        'own',
        'ownresponse',
        'removed',
    ];

    protected function init()
    {
        foreach (static::$beforeParentInit as $name => $value) {
            $this->getResponse()->addHeader($name, $value);
        }
        parent::init();
        foreach (static::$afterParentInit as $name => $value) {
            $this->getResponse()->addHeader($name, $value);
        }
    }

    /**
     * Sets the headers on the controller's response, then renders the page template.
     */
    public function own()
    {
        foreach (static::$inAction as $name => $value) {
            $this->getResponse()->addHeader($name, $value);
        }
        return $this;
    }

    /**
     * Returns a response of its own carrying the headers, as an export or a feed endpoint would.
     */
    public function ownresponse(): HTTPResponse
    {
        $response = HTTPResponse::create('feed body', 200);
        foreach (static::$inAction as $name => $value) {
            $response->addHeader($name, $value);
        }
        return $response;
    }

    /**
     * Removes the headers by name: the project does not want them on this action.
     */
    public function removed()
    {
        foreach (array_keys(static::$inAction) as $name) {
            $this->getResponse()->removeHeader($name);
        }
        return $this;
    }
}
