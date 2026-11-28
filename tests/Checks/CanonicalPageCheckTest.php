<?php

namespace Restruct\Silverstripe\SEO\Tests\Checks;

use SilverStripe\i18n\i18n;

/**
 * The canonical check with a picked canonical page: a published page is judged like any canonical that points
 * elsewhere; one that is not published (any more) or is gone is reported, since the page then quietly points to
 * itself instead.
 */
class CanonicalPageCheckTest extends ChecksTestCase
{
    public function testAPublishedPickedPageIsACanonicalElsewhere(): void
    {
        $original = $this->page(['URLSegment' => 'the-original'], [], true);
        $page = $this->page(['CanonicalPageID' => $original->ID]);
        $this->assertCheckFails($page, 'canonical', 'the-original');
    }

    public function testAnUnpublishedPickedPageIsReported(): void
    {
        $draftOnly = $this->page(['Title' => 'Draft original']);
        $page = $this->page(['CanonicalPageID' => $draftOnly->ID]);
        $entry = $this->assertCheckFails($page, 'canonical');
        $this->assertStringContainsString('not published', $entry['message']);
        $this->assertStringContainsString('Draft original', implode("\n", $entry['details']));
    }

    public function testADeletedPickedPageIsReported(): void
    {
        $original = $this->page(['Title' => 'Gone original'], [], true);
        $page = $this->page(['CanonicalPageID' => $original->ID]);
        $original->doArchive();
        $entry = $this->assertCheckFails($page, 'canonical');
        $this->assertStringContainsString('no longer exists', $entry['message']);
    }

    public function testTheMessageIsTranslated(): void
    {
        $draftOnly = $this->page();
        $page = $this->page(['CanonicalPageID' => $draftOnly->ID]);
        $entry = i18n::with_locale('nl_NL', fn () => $this->entry($page, 'canonical'));
        $this->assertStringContainsString('niet gepubliceerd', $entry['message']);
    }
}
