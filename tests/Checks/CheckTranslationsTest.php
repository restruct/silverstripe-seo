<?php

namespace Restruct\Silverstripe\SEO\Tests\Checks;

use SilverStripe\Dev\SapphireTest;
use Symfony\Component\Yaml\Yaml;

/**
 * Every English string of the module has a Dutch one, so the panel never shows a mix of languages.
 */
class CheckTranslationsTest extends SapphireTest
{
    public function testEveryEnglishStringHasADutchOne(): void
    {
        $lang = dirname(__DIR__, 2) . '/lang';
        $en = Yaml::parseFile("$lang/en.yml")['en'];
        $nl = Yaml::parseFile("$lang/nl.yml")['nl'];
        $missing = [];
        foreach ($en as $class => $keys) {
            foreach (array_keys($keys) as $key) {
                if (!isset($nl[$class][$key])) {
                    $missing[] = "$class.$key";
                }
            }
        }
        $this->assertSame([], $missing);
        $this->assertSame('{count} probleem', $nl['Restruct\\Silverstripe\\SEO\\Forms\\SeoChecksField']['CountProblems']['one']);
    }
}
