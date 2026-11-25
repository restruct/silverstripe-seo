<?php

namespace Restruct\Silverstripe\SEO\Tests\Checks;

use Restruct\Silverstripe\SEO\Checks\CheckRunner;
use Restruct\Silverstripe\SEO\Tests\Stub\Checks\SeoCheckTestBlock;
use Restruct\Silverstripe\SEO\Tests\Stub\Checks\SeoCheckTestPage;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Security\Security;
use SilverStripe\SiteConfig\SiteConfig;
use SilverStripe\View\SSViewer;

/**
 * Runs the checks on real pages, rendered in-process through the test page's own template (see
 * {@see SeoCheckTestPage}), as a logged-in CMS user on the draft stage: the way the CMS panel runs them.
 */
abstract class ChecksTestCase extends SapphireTest
{
    protected $usesDatabase = true;

    protected static $extra_dataobjects = [
        SeoCheckTestPage::class,
        SeoCheckTestBlock::class,
    ];

    /**
     * About seventy words of ordinary text: long enough for the thin-content check, no placeholders.
     */
    protected const GOOD_TEXT = '<p>Our evening courses for adults cover languages, cooking, photography and woodwork. '
        . 'Each course runs for ten weeks, with small groups and teachers who work in the field themselves. '
        . 'You can join at the start of every term, and most courses need no experience at all. '
        . 'Materials are included in the price, and the classrooms are close to the station, '
        . 'so you can come straight from work. Ask us anything before you sign up.</p>';

    protected const GOOD_DESCRIPTION = 'Evening courses for adults in languages, cooking, photography and woodwork: '
        . 'small groups, ten weeks, no experience needed.';

    protected function setUp(): void
    {
        parent::setUp();
        # PageRenderer renders with the configured front-end themes (not the ones set at runtime), so the test
        # theme goes into config. The module's own theme, addressed through the module so it resolves wherever
        # the module is installed
        SSViewer::config()->set('themes', ['restruct/silverstripe-seo:/tests/themes/seotest', '$default']);

        $config = SiteConfig::current_site_config();
        $config->Title = 'Example Site';
        $config->write();

        $this->logInWithPermission('ADMIN');
    }

    /**
     * A draft page that passes every check except the ones a test sets up to fail (it is an orphan only when
     * not shown in menus).
     *
     * @param array<int, array<string, mixed>> $blocks Data for content blocks rendered after the content
     */
    protected function page(array $data = [], array $blocks = [], bool $publish = false): SeoCheckTestPage
    {
        static $n = 0;
        $n++;
        $page = SeoCheckTestPage::create(array_merge([
            'Title' => 'Evening courses for adults ' . $n,
            'URLSegment' => 'evening-courses-' . $n,
            'MetaDescription' => static::GOOD_DESCRIPTION . ' ' . $n,
            'Content' => static::GOOD_TEXT,
        ], $data));
        $page->write();
        foreach ($blocks as $i => $block) {
            SeoCheckTestBlock::create(array_merge(['PageID' => $page->ID, 'Sort' => $i + 1], $block))->write();
        }
        if ($publish) {
            $page->publishRecursive();
        }
        return $page;
    }

    /**
     * @return array<string, array> Result entries keyed by check id
     */
    protected function results(SiteTree $page): array
    {
        return CheckRunner::singleton()->run($page, Security::getCurrentUser());
    }

    protected function entry(SiteTree $page, string $id): array
    {
        $results = $this->results($page);
        $this->assertArrayHasKey($id, $results, "check $id should have run");
        return $results[$id];
    }

    /**
     * @return array The failing entry, for further assertions on its message and details
     */
    protected function assertCheckFails(SiteTree $page, string $id, ?string $inDetails = null): array
    {
        $entry = $this->entry($page, $id);
        $this->assertSame('fail', $entry['status'], "$id should fail: " . json_encode($entry));
        $this->assertNotSame('', $entry['message'], "$id should say what is wrong");
        $this->assertNotSame('', $entry['howToFix'], "$id should say how to fix it");
        if ($inDetails !== null) {
            $this->assertStringContainsString($inDetails, implode("\n", $entry['details']), "$id details");
        }
        return $entry;
    }

    protected function assertCheckPasses(SiteTree $page, string $id): void
    {
        $entry = $this->entry($page, $id);
        $this->assertSame('pass', $entry['status'], "$id should pass: " . json_encode($entry));
    }
}
