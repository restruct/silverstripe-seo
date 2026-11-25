<?php

namespace Restruct\Silverstripe\SEO\Tests\Stub\Checks;

/**
 * A project's check that points at its own documentation.
 */
class SeoDocumentedTestCheck extends SeoCustomTestCheck
{
    private static string $id = 'custom-documented';

    public function docsUrl(): string
    {
        return 'https://docs.example.test/seo/custom-documented';
    }
}
