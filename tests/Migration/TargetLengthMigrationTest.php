<?php

namespace Restruct\Silverstripe\SEO\Tests\Migration;

/**
 * A value longer than the target column holds is invalid, never cut off. Silverstripe's ANSI sql_mode is not
 * strict, so MySQL would otherwise truncate it without a word.
 */
class TargetLengthMigrationTest extends SeoMigrationTestCase
{
    public function testValuesLongerThanTheTargetAreInvalidAndNotWritten(): void
    {
        # jellygnite's own columns are Varchar(255); wider here, as another module's could be
        $this->addOldStagedColumn('Page', 'MetaTitle', 'mediumtext');
        $this->addOldStagedColumn('Page', 'CanonicalURL', 'mediumtext');
        $page = $this->publishedPage('Long');
        $canonical = 'https://www.example.com/' . str_repeat('a', 2096);
        $this->assertSame(2120, strlen($canonical));
        foreach (['Page', 'Page_Live'] as $table) {
            $this->setRaw($table, $page->ID, ['MetaTitle' => str_repeat('t', 300), 'CanonicalURL' => $canonical]);
        }

        $output = $this->runMigration('jellygnite', ['--apply' => true]);

        $this->assertReportLine($output, 'MetaTitle -> MetaTitle [draft]:', ['read 1', 'copied 0', 'invalid 1']);
        $this->assertReportLine($output, 'MetaTitle -> MetaTitle [live]:', ['read 1', 'copied 0', 'invalid 1']);
        $this->assertReportLine($output, 'CanonicalURL -> CanonicalURL [draft]:', ['read 1', 'copied 0', 'invalid 1']);
        $this->assertEmpty($this->raw('SiteTree', 'MetaTitle', $page->ID));
        $this->assertEmpty($this->raw('SiteTree_Live', 'MetaTitle', $page->ID));
        $this->assertEmpty($this->raw('SiteTree', 'CanonicalURL', $page->ID));

        $again = $this->runMigration('jellygnite', ['--apply' => true]);
        $this->assertReportLine($again, 'MetaTitle -> MetaTitle [draft]:', ['copied 0', 'already same 0', 'invalid 1']);
        $this->assertEmpty($this->raw('SiteTree', 'MetaTitle', $page->ID));
    }
}
