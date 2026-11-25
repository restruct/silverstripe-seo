<?php

namespace Restruct\Silverstripe\SEO\Tests\Stub\Schema;

use Restruct\Silverstripe\SEO\Schema\SchemaContext;
use Restruct\Silverstripe\SEO\Tests\Stub\SeoTestPage;
use RuntimeException;

/**
 * A page whose structured data goes wrong in the ways real data does: a provider that throws (a corrupt API
 * cache) or a value JSON cannot encode (NAN from a calculation). The mode is a public static, so each test picks
 * its fault.
 */
class SchemaTestFaultyPage extends SeoTestPage
{
    private static string $table_name = 'SchemaTestFaultyPage';

    public static string $mode = 'none';

    public function provideSchemaData(SchemaContext $ctx): ?array
    {
        if (static::$mode === 'throw') {
            throw new RuntimeException('cached API data corrupt');
        }
        if (static::$mode === 'nan') {
            return ['@type' => 'Thing', '@id' => $ctx->pageId('thing'), 'name' => 'Measured', 'value' => NAN];
        }
        return null;
    }
}
