<?php

namespace Restruct\Silverstripe\SEO\Tests\Stub;

use Restruct\Silverstripe\SEO\Tests\Stub\Checks\SeoCheckTestPage;
use SilverStripe\Dev\TestOnly;

/**
 * A page type that decides its SEO values in code, as a course page with generated titles does: a getter
 * override of MetaTitle and MetaRobotsNoIndex that ignores the columns, and a MetaDescriptionFallback() method.
 * Extends the checks' test page for its template and controller.
 *
 * @property string $CourseCode
 * @property bool $Archived
 * @property string $FallbackText
 */
class SeoGetterTestPage extends SeoCheckTestPage implements TestOnly
{
    private static string $table_name = 'SeoGetterTestPage';

    private static array $db = [
        'CourseCode' => 'Varchar(20)',
        # An archived course is never indexed, whatever the robots checkbox says
        'Archived' => 'Boolean',
        # What MetaDescriptionFallback() returns; empty leaves the fallback to the module
        'FallbackText' => 'Varchar(255)',
    ];

    /**
     * The title is generated from the course code; the MetaTitle column is ignored.
     */
    public function getMetaTitle(): string
    {
        return 'Course ' . $this->CourseCode;
    }

    /**
     * Noindex follows the archived flag only; the MetaRobotsNoIndex column is ignored.
     */
    public function getMetaRobotsNoIndex(): bool
    {
        return (bool) $this->Archived;
    }

    public function MetaDescriptionFallback(): string
    {
        return (string) $this->FallbackText;
    }
}
