<?php

namespace Restruct\Silverstripe\SEO\Tests\Schema;

use Restruct\Silverstripe\SEO\Schema\SchemaGraph;
use SilverStripe\Dev\SapphireTest;

/**
 * The container for a page's structured data: one `@graph`, nodes deduplicated by `@id`, and JSON that can sit
 * inside a `<script>` element whatever the data contains.
 */
class SchemaGraphTest extends SapphireTest
{
    public function testShape(): void
    {
        $graph = SchemaGraph::create()
            ->add(['@type' => 'WebSite', '@id' => 'https://example.com/#website', 'name' => 'Example'])
            ->add(['@type' => 'Thing', 'name' => 'No id']);

        $this->assertSame([
            '@context' => 'https://schema.org',
            '@graph' => [
                ['@type' => 'WebSite', '@id' => 'https://example.com/#website', 'name' => 'Example'],
                ['@type' => 'Thing', 'name' => 'No id'],
            ],
        ], $graph->toArray());
    }

    public function testNodesWithTheSameIdAreMergedOnce(): void
    {
        $graph = SchemaGraph::create()
            ->add(['@type' => 'Organization', '@id' => 'https://example.com/#organization', 'name' => 'First'])
            ->add(['@type' => 'Organization', '@id' => 'https://example.com/#organization', 'name' => 'Second', 'url' => 'https://example.com/']);

        $nodes = $graph->nodes();
        $this->assertCount(1, $nodes);
        # The first description wins; a later one only fills in what was missing
        $this->assertSame('First', $nodes[0]['name']);
        $this->assertSame('https://example.com/', $nodes[0]['url']);
    }

    public function testNodesWithoutAnIdAreNeverMerged(): void
    {
        $graph = SchemaGraph::create()->add(['@type' => 'Thing'])->add(['@type' => 'Thing']);
        $this->assertCount(2, $graph->nodes());
    }

    public function testJsonIsSafeInsideAScriptElement(): void
    {
        $graph = SchemaGraph::create()->add([
            '@type' => 'Thing',
            'name' => 'Break </script><script>alert(1)</script> out <!-- here',
            'url' => 'https://example.com/a/b',
            'description' => 'Één café',
        ]);
        $json = $graph->toJson();

        $this->assertStringNotContainsString('</script', $json);
        $this->assertStringNotContainsString('<', $json);
        $this->assertStringNotContainsString('>', $json);
        # Slashes and non-ASCII letters stay readable
        $this->assertStringContainsString('"https://example.com/a/b"', $json);
        $this->assertStringContainsString('Één café', $json);
        # And the data survives the round trip
        $this->assertSame(
            'Break </script><script>alert(1)</script> out <!-- here',
            json_decode($json, true)['@graph'][0]['name']
        );
    }

    public function testInvalidUtf8DoesNotEmptyTheGraph(): void
    {
        $json = SchemaGraph::create()->add(['@type' => 'Thing', 'name' => "bad \xB1 byte"])->toJson();
        $this->assertNotSame('', $json);
        $this->assertSame('Thing', json_decode($json, true)['@graph'][0]['@type']);
    }

    public function testAValueJsonCannotEncodeIsDroppedNotTheGraph(): void
    {
        $json = SchemaGraph::create()->add([
            '@type' => 'Thing',
            'name' => 'Measured',
            'value' => NAN,
            'nested' => ['@type' => 'QuantitativeValue', 'value' => INF, 'unitText' => 'm'],
        ])->toJson();

        $this->assertNotSame('', $json);
        $node = json_decode($json, true)['@graph'][0];
        $this->assertSame('Measured', $node['name']);
        $this->assertArrayNotHasKey('value', $node);
        $this->assertSame(['@type' => 'QuantitativeValue', 'unitText' => 'm'], $node['nested']);
    }

    public function testDanglingReferencesUnderThePrefixArePruned(): void
    {
        $graph = SchemaGraph::create()
            ->add(['@type' => 'WebSite', '@id' => 'https://example.com/#website'])
            ->add([
                '@type' => 'WebPage',
                '@id' => 'https://example.com/page#webpage',
                'isPartOf' => ['@id' => 'https://example.com/#website'],
                'publisher' => ['@id' => 'https://example.com/#organization'],
                'about' => [['@id' => 'https://example.com/#organization'], ['@id' => 'https://example.com/#website']],
                # Another site's node: not ours to judge
                'sameAs' => ['@id' => 'https://www.wikidata.org/wiki/Q1'],
            ]);

        $removed = $graph->pruneReferences('https://example.com/');
        $page = $graph->get('https://example.com/page#webpage');

        $this->assertTrue($removed);
        $this->assertSame(['@id' => 'https://example.com/#website'], $page['isPartOf']);
        $this->assertArrayNotHasKey('publisher', $page);
        $this->assertSame([['@id' => 'https://example.com/#website']], $page['about']);
        $this->assertSame(['@id' => 'https://www.wikidata.org/wiki/Q1'], $page['sameAs']);
        $this->assertFalse($graph->pruneReferences('https://example.com/'), 'nothing left to prune');
    }

    public function testRemove(): void
    {
        $graph = SchemaGraph::create()->add(['@type' => 'Thing', '@id' => 'x']);
        $this->assertTrue($graph->has('x'));
        $graph->remove('x');
        $this->assertFalse($graph->has('x'));
        $this->assertTrue($graph->isEmpty());
    }
}
