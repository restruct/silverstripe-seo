<?php

namespace Restruct\Silverstripe\SEO\Tests\Checks;

use SilverStripe\Dev\SapphireTest;

/**
 * The admin's entwine (silverstripe/admin, SS 6.3) does not match a selector with a valueless attribute test:
 * `.seo-checks[data-seo-checks]` never matched the panel (`data-seo-checks=""`), so the rule's methods did not
 * exist on it and Re-check, ignore and the re-check on open did nothing, without a console error. jQuery's own
 * `.is()` says such a selector matches, so it cannot be checked that way either. The module's rules therefore
 * select by class (and the form's own events) only; this pins that for every entwine rule in the module's JS.
 *
 * Static, because the suite has no browser or DOM: it reads the selectors from the source.
 */
class EntwineSelectorsTest extends SapphireTest
{
    public function testNoEntwineSelectorUsesAnAttributeTest(): void
    {
        $files = glob(dirname(__DIR__, 2) . '/client/dist/js/*.js');
        $this->assertNotEmpty($files);
        $rules = 0;
        foreach ($files as $file) {
            $js = (string) file_get_contents($file);
            preg_match_all('/\$\(\s*([\'"])(.*?)\1\s*\)\s*\.entwine\(/s', $js, $matches);
            foreach ($matches[2] as $selector) {
                $rules++;
                $this->assertStringNotContainsString('[', $selector, basename($file) . ": entwine selector \"$selector\" has an attribute test");
            }
        }
        $this->assertGreaterThan(0, $rules, 'control: the entwine rules were found');
    }
}
