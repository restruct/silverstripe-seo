<?php

namespace Restruct\Silverstripe\SEO\Tests\Migration;

use SilverStripe\Control\Controller;
use SilverStripe\ORM\DB;

/**
 * bigfork/silverstripe-simpleseo with kinglozzer/metatitle: MetaTitle is the same column; the canonical is a
 * silverstripe/linkfield Link (has_one CanonicalLink), resolved to a URL per stage.
 */
class SimpleSeoMigrationTest extends SeoMigrationTestCase
{
    private const LINK = 'SilverStripe\\LinkField\\Models\\';

    protected function setUp(): void
    {
        parent::setUp();
        $this->addOldStagedColumn('SiteTree', 'CanonicalLinkID', 'int NOT NULL DEFAULT 0');
        # silverstripe/linkfield's tables (Link is versioned)
        foreach (['', '_Live'] as $stage) {
            $this->createOldTable('LinkField_Link' . $stage, ['ClassName' => 'varchar(255)']);
            $this->createOldTable('LinkField_ExternalLink' . $stage, ['ExternalUrl' => 'varchar(255)']);
            $this->createOldTable('LinkField_SiteTreeLink' . $stage, [
                'PageID' => 'int NOT NULL DEFAULT 0',
                'Anchor' => 'varchar(255)',
                'QueryString' => 'varchar(255)',
            ]);
            $this->createOldTable('LinkField_FileLink' . $stage, ['FileID' => 'int NOT NULL DEFAULT 0']);
            $this->createOldTable('LinkField_EmailLink' . $stage, ['Email' => 'varchar(255)']);
        }
    }

    private function link(int $id, string $type, array $values, array $stages = ['', '_Live']): void
    {
        foreach ($stages as $stage) {
            $this->insertRaw('LinkField_Link' . $stage, ['ID' => $id, 'ClassName' => self::LINK . $type]);
            $this->insertRaw('LinkField_' . $type . $stage, array_merge(['ID' => $id], $values));
        }
    }

    private function canonicalLink(int $pageID, int $linkID, bool $live = true): void
    {
        $this->setRaw('SiteTree', $pageID, ['CanonicalLinkID' => $linkID]);
        if ($live) {
            $this->setRaw('SiteTree_Live', $pageID, ['CanonicalLinkID' => $linkID]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function seed(): array
    {
        $master = $this->publishedPage('Master', ['URLSegment' => 'master']);
        $image = $this->createTestImage('brochure.png');

        $external = $this->publishedPage('External');
        # The draft link was changed and not yet published: each stage resolves its own version
        $this->link(1, 'ExternalLink', ['ExternalUrl' => 'https://www.example.com/draft'], ['']);
        $this->link(1, 'ExternalLink', ['ExternalUrl' => 'https://www.example.com/live'], ['_Live']);
        $this->canonicalLink($external->ID, 1);

        $toPage = $this->publishedPage('Copy');
        $this->link(2, 'SiteTreeLink', ['PageID' => $master->ID, 'Anchor' => 'top', 'QueryString' => 'a=1']);
        $this->canonicalLink($toPage->ID, 2);

        $toFile = $this->publishedPage('File');
        $this->link(3, 'FileLink', ['FileID' => $image->ID]);
        $this->canonicalLink($toFile->ID, 3);

        # A canonical to the page itself is the default already: nothing to copy, and not counted as read
        $self = $this->publishedPage('Self');
        $this->link(4, 'SiteTreeLink', ['PageID' => $self->ID]);
        $this->canonicalLink($self->ID, 4);

        $email = $this->publishedPage('Email');
        $this->link(5, 'EmailLink', ['Email' => 'info@example.com']);
        $this->canonicalLink($email->ID, 5);

        $gone = $this->publishedPage('Gone');
        $this->canonicalLink($gone->ID, 99, false);

        $kept = $this->publishedPage('Kept', ['CanonicalURL' => 'https://www.example.com/kept']);
        $kept->publishSingle();
        $this->link(6, 'ExternalLink', ['ExternalUrl' => 'https://www.example.com/other']);
        $this->canonicalLink($kept->ID, 6);

        return compact('master', 'image', 'external', 'toPage', 'toFile', 'self', 'email', 'gone', 'kept');
    }

    public function testDryRunReportsAndWritesNothing(): void
    {
        $pages = $this->seed();

        $output = $this->runMigration('simpleseo');

        $this->assertStringContainsString('Dry run', $output);
        $this->assertReportLine($output, 'MetaTitle:', ['same column', 'nothing to copy']);
        $this->assertReportLine($output, 'CanonicalLinkID -> CanonicalURL [draft]:', [
            'read 6', 'would copy 3', 'conflicts 1', 'invalid 2',
        ]);
        $this->assertReportLine($output, 'Note:', ['ExtraMeta']);
        $this->assertEmpty($this->raw('SiteTree', 'CanonicalURL', $pages['external']->ID));
    }

    public function testApplyResolvesEachLinkTypePerStage(): void
    {
        $pages = $this->seed();

        $this->runMigration('simpleseo', ['--apply' => true]);

        $this->assertSame('https://www.example.com/draft', $this->raw('SiteTree', 'CanonicalURL', $pages['external']->ID));
        $this->assertSame('https://www.example.com/live', $this->raw('SiteTree_Live', 'CanonicalURL', $pages['external']->ID));
        $this->assertSame(
            Controller::join_links($pages['master']->Link(), '#top', '?a=1'),
            $this->raw('SiteTree', 'CanonicalURL', $pages['toPage']->ID)
        );
        $this->assertSame($pages['image']->getURL(), $this->raw('SiteTree_Live', 'CanonicalURL', $pages['toFile']->ID));
        $this->assertEmpty($this->raw('SiteTree', 'CanonicalURL', $pages['self']->ID));
        $this->assertEmpty($this->raw('SiteTree', 'CanonicalURL', $pages['email']->ID));
        $this->assertEmpty($this->raw('SiteTree', 'CanonicalURL', $pages['gone']->ID));
        $this->assertSame('https://www.example.com/kept', $this->raw('SiteTree', 'CanonicalURL', $pages['kept']->ID));

        $again = $this->runMigration('simpleseo', ['--apply' => true]);
        $this->assertReportLine($again, 'CanonicalLinkID -> CanonicalURL [draft]:', ['copied 0', 'already same 3']);
    }

    public function testWithoutLinkTablesTheLinksAreInvalid(): void
    {
        $page = $this->publishedPage('External');
        $this->canonicalLink($page->ID, 1);
        foreach (['', '_Live'] as $stage) {
            DB::query(sprintf('DROP TABLE "LinkField_Link%s"', $stage));
        }

        $output = $this->runMigration('simpleseo', ['--apply' => true]);

        $this->assertReportLine($output, 'CanonicalLinkID -> CanonicalURL [draft]:', ['read 1', 'invalid 1']);
        $this->assertReportLine($output, 'Note:', ['LinkField_Link', 'not found']);
    }
}
