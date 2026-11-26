<?php

namespace Restruct\Silverstripe\SEO\Tests\Checks;

use Restruct\Silverstripe\SEO\Tests\Stub\Checks\SeoCheckTestBlock;
use Restruct\Silverstripe\SEO\Tests\Stub\Checks\SeoCheckTestPage;
use Restruct\Silverstripe\SEO\Tests\Stub\SeoGetterTestPage;

/**
 * The checks read titles and robots the way the page class decides them: a getter override of MetaTitle or
 * MetaRobotsNoIndex counts, the column it ignores does not.
 */
class GetterChecksTest extends ChecksTestCase
{
    # Replaces the parent's list, so its classes are named again
    protected static $extra_dataobjects = [
        SeoCheckTestPage::class,
        SeoCheckTestBlock::class,
        SeoGetterTestPage::class,
    ];

    private function coursePage(array $data = [], bool $publish = false): SeoGetterTestPage
    {
        static $n = 0;
        $n++;
        $page = SeoGetterTestPage::create(array_merge([
            'Title' => 'Course page ' . $n,
            'URLSegment' => 'course-' . $n,
            'CourseCode' => 'CODE' . $n,
            'MetaDescription' => static::GOOD_DESCRIPTION . ' ' . $n,
            'Content' => static::GOOD_TEXT,
        ], $data));
        $page->write();
        if ($publish) {
            $page->publishRecursive();
        }
        return $page;
    }

    public function testNoindexPublishedSeesAGetterNoindex(): void
    {
        $page = $this->coursePage(['Archived' => true, 'MetaRobotsNoIndex' => false], true);

        $this->assertCheckFails($page, 'noindex-published');
        $this->assertSame(['noindex-published'], array_keys($this->results($page)), 'only the noindex check');
    }

    public function testAGetterIndexablePageGetsTheWholeCatalogue(): void
    {
        $page = $this->coursePage(['Archived' => false, 'MetaRobotsNoIndex' => true], true);

        $this->assertCheckPasses($page, 'noindex-published');
        $this->assertArrayHasKey('title-duplicate', $this->results($page));
    }

    public function testTitleChecksReadTheGeneratedTitle(): void
    {
        # The column holds a placeholder, which the page class ignores
        $page = $this->coursePage(['MetaTitle' => 'New Page']);

        $this->assertCheckPasses($page, 'title-boilerplate');
        $this->assertCheckPasses($page, 'title-missing');
    }

    public function testTwoGeneratedTitlesThatAreTheSameAreDuplicates(): void
    {
        $this->coursePage(['CourseCode' => 'SAME']);
        $page = $this->coursePage(['CourseCode' => 'SAME']);

        $this->assertCheckFails($page, 'title-duplicate', 'Course page');
    }

    public function testAGeneratedTitleIsComparedWithOtherPagesTitles(): void
    {
        # A plain page titled like the generated title, and the other way round
        $plain = $this->page(['Title' => 'Course XYZ']);
        $course = $this->coursePage(['CourseCode' => 'XYZ']);

        $this->assertCheckFails($course, 'title-duplicate', 'Course XYZ');
        $this->assertCheckFails($plain, 'title-duplicate', 'Course page');
    }

    public function testTheIgnoredColumnIsNotCompared(): void
    {
        # Both columns say "Shared title", but the page class generates different titles
        $this->coursePage(['MetaTitle' => 'Shared title', 'CourseCode' => 'ONE']);
        $page = $this->coursePage(['MetaTitle' => 'Shared title', 'CourseCode' => 'TWO']);
        $plain = $this->page(['MetaTitle' => 'Shared title']);

        $this->assertCheckPasses($page, 'title-duplicate');
        $this->assertCheckPasses($plain, 'title-duplicate');
    }

    public function testDuplicatesFollowTheGetterNoindex(): void
    {
        # Not indexable by its getter (the column says indexable): not a duplicate
        $this->coursePage(['CourseCode' => 'SAME', 'Archived' => true]);
        $page = $this->coursePage(['CourseCode' => 'SAME']);
        $this->assertCheckPasses($page, 'title-duplicate');

        # Indexable by its getter (the column says noindex): a duplicate
        $this->coursePage(['CourseCode' => 'OTHER', 'MetaRobotsNoIndex' => true]);
        $page = $this->coursePage(['CourseCode' => 'OTHER']);
        $this->assertCheckFails($page, 'title-duplicate');
    }
}
