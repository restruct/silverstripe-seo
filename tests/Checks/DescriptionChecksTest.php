<?php

namespace Restruct\Silverstripe\SEO\Tests\Checks;

use Restruct\Silverstripe\SEO\Checks\Rules\DescriptionLengthCheck;

/**
 * Checks 6-8: the meta description, typed or derived.
 */
class DescriptionChecksTest extends ChecksTestCase
{
    public function testMissingPassesWithATypedDescription(): void
    {
        $this->assertCheckPasses($this->page(), 'description-missing');
    }

    public function testMissingPassesWhenTheFallbackIsGood(): void
    {
        # No typed description, but the content gives a fallback of a usable length
        $this->assertCheckPasses($this->page(['MetaDescription' => '']), 'description-missing');
    }

    public function testMissingFailsWhenTheFallbackIsWeak(): void
    {
        # The text is in a block, not in Content, so the fallback (from Content) is empty; the suggestion is taken
        # from the rendered page instead
        $page = $this->page(['MetaDescription' => '', 'Content' => ''], [
            ['Title' => 'About', 'Content' => static::GOOD_TEXT],
        ]);
        $this->assertCheckFails($page, 'description-missing', 'Our evening courses for adults');
    }

    public function testDuplicatePassesForAUniqueDescription(): void
    {
        $this->page();
        $this->assertCheckPasses($this->page(), 'description-duplicate');
    }

    public function testDuplicateFailsForTheSameTypedDescription(): void
    {
        $this->page(['MetaDescription' => static::GOOD_DESCRIPTION, 'URLSegment' => 'first-one']);
        $page = $this->page(['MetaDescription' => static::GOOD_DESCRIPTION]);
        $this->assertCheckFails($page, 'description-duplicate', 'first-one');
    }

    public function testDuplicateIgnoresEmptyDescriptions(): void
    {
        $this->page(['MetaDescription' => '']);
        $this->assertCheckPasses($this->page(['MetaDescription' => '']), 'description-duplicate');
    }

    public function testLengthPassesWithinTheRange(): void
    {
        $this->assertCheckPasses($this->page(), 'description-length');
    }

    public function testLengthFailsWhenTooShortOrTooLong(): void
    {
        $this->assertCheckFails($this->page(['MetaDescription' => 'Courses.']), 'description-length');
        $this->assertCheckFails(
            $this->page(['MetaDescription' => str_repeat('A long description. ', 12)]),
            'description-length'
        );
    }

    public function testLengthThresholdsAreConfigurable(): void
    {
        DescriptionLengthCheck::config()->set('min_length', 5);
        $this->assertCheckPasses($this->page(['MetaDescription' => 'Courses.']), 'description-length');
    }
}
