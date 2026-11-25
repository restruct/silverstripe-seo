<?php

namespace Restruct\Silverstripe\SEO\Tests\Stub\Schema;

use Restruct\Silverstripe\SEO\Schema\SchemaContext;
use SilverStripe\Core\Extension;
use SilverStripe\Dev\TestOnly;

/**
 * The `updateSchemaData` hook a project uses to tweak a node without changing the map. Inert unless a test
 * switches it on.
 */
class SchemaTestDataExtension extends Extension implements TestOnly
{
    public static bool $active = false;

    protected function updateSchemaData(array &$node, SchemaContext $ctx): void
    {
        if (static::$active) {
            $node['typicalAgeRange'] = '16-';
            $node['seenPage'] = $ctx->getPage()->Title;
        }
    }
}
