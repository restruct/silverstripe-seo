<?php

namespace Restruct\Silverstripe\SEO\Tests;

use Restruct\Silverstripe\SEO\Checks\CheckRunner;
use SilverStripe\Dev\SapphireTest;

/**
 * What Composer installs from Packagist is the dist archive, built by `git archive` with the export-ignore
 * attributes of .gitattributes. Everything the module needs at runtime must be in it: the check specs in
 * docs/checks/ too, which the "About this check" dialog renders (0.6.1 export-ignored /docs, so the dialog never
 * opened on an installed copy).
 *
 * Needs a git checkout of the module (tests are not in the dist archive anyway); skipped otherwise. Archives the
 * committed tree with the working tree's attributes, so a change to .gitattributes counts before it is committed.
 */
class DistArchiveTest extends SapphireTest
{
    /**
     * @return string[] paths in the dist archive, relative to the module root
     */
    private function archiveListing(): array
    {
        $root = realpath(dirname(__DIR__));
        $top = trim((string) shell_exec('git -C ' . escapeshellarg($root) . ' rev-parse --show-toplevel 2>/dev/null'));
        if ($top === '' || realpath($top) !== $root) {
            $this->markTestSkipped('Not a git checkout of the module');
        }
        $listing = (string) shell_exec('git -C ' . escapeshellarg($root)
            . ' archive --worktree-attributes --format=tar HEAD | tar -t 2>/dev/null');
        $paths = array_values(array_filter(array_map('trim', explode("\n", $listing))));
        $this->assertNotEmpty($paths, 'git archive gave a listing');
        return $paths;
    }

    public function testEveryRegisteredModuleCheckHasItsSpecInTheArchive(): void
    {
        $paths = $this->archiveListing();
        $ids = [];
        foreach (CheckRunner::singleton()->getChecks() as $id => $check) {
            if (str_starts_with(get_class($check), 'Restruct\\Silverstripe\\SEO\\Checks\\Rules\\')) {
                $ids[] = $id;
                $this->assertContains("docs/checks/$id.md", $paths, "spec of $id");
            }
        }
        $this->assertCount(23, $ids, 'control: the module checks were found');
    }

    public function testTheRuntimePathsAreInTheArchive(): void
    {
        $paths = $this->archiveListing();
        foreach ([
            'composer.json',
            '_config/checks.yml',
            'src/Checks/CheckRunner.php',
            'templates/Restruct/Silverstripe/SEO/Forms/SeoChecksField.ss',
            'lang/en.yml',
            'lang/nl.yml',
            'client/dist/js/seo-checks.js',
            'client/dist/css/seo-checks.css',
        ] as $path) {
            $this->assertContains($path, $paths, $path);
        }
        # Control: the export-ignore attributes are applied at all
        $this->assertNotContains('tests/DistArchiveTest.php', $paths);
    }
}
