<?php

namespace Restruct\Silverstripe\SEO\Tests\Stub\Schema;

use Restruct\Silverstripe\SEO\Schema\SchemaContext;
use Restruct\Silverstripe\SEO\Tests\Stub\SeoTestPage;

/**
 * A page whose courses come from an external API and are cached on the page as JSON, so there is no record per
 * course: provideSchemaData() builds the nodes from that data. Returning a list gives several nodes.
 *
 * @property string $CourseCache
 */
class SchemaTestCoursePage extends SeoTestPage
{
    private static string $table_name = 'SchemaTestCoursePage';

    private static array $db = [
        'CourseCache' => 'Text',
    ];

    public function provideSchemaData(SchemaContext $ctx): ?array
    {
        $courses = json_decode((string) $this->CourseCache, true);
        if (!is_array($courses) || !$courses) {
            return null;
        }
        $nodes = [];
        foreach ($courses as $course) {
            $nodes[] = [
                '@type' => 'Course',
                '@id' => $ctx->pageId('course-' . $course['code']),
                'name' => $course['name'] ?? null,
                'description' => $course['summary'] ?? null,
                'provider' => ['@id' => $ctx->siteId('organization')],
                'hasCourseInstance' => array_map(fn (array $run) => [
                    '@type' => 'CourseInstance',
                    'courseMode' => $run['mode'],
                    # A date and a time as the API delivers them, combined with the site's timezone
                    'startDate' => $ctx->combineDateTime($run['date'], $run['time']),
                ], $course['runs'] ?? []),
            ];
        }
        return $nodes;
    }
}
