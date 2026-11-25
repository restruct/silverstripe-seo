<?php

namespace Restruct\Silverstripe\SEO\Schema;

use Psr\Log\LoggerInterface;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Core\Injector\Injector;

/**
 * The structured data of one page: a schema.org `@graph` of nodes, output as a single JSON-LD script.
 *
 * Nodes with the same `@id` are one node: the first one added wins, a later one only fills in properties the
 * first lacked. So the organisation is described once however many events and job postings point at it.
 */
class SchemaGraph
{
    use Injectable;

    /**
     * JSON flags for the output. JSON_HEX_TAG writes `<` and `>` as `<` and `>`, so no value can close
     * the script element (`</script>`) or open an HTML comment (`<!--`), whatever an editor typed. Slashes and
     * non-ASCII letters stay as they are, for readable output.
     */
    public const JSON_FLAGS = JSON_UNESCAPED_SLASHES
        | JSON_UNESCAPED_UNICODE
        | JSON_HEX_TAG
        | JSON_INVALID_UTF8_SUBSTITUTE;

    /**
     * Nodes by `@id`, or by a running number for nodes without one.
     *
     * @var array<string|int,array>
     */
    protected array $nodes = [];

    public function add(array $node): static
    {
        $id = isset($node['@id']) && is_string($node['@id']) && $node['@id'] !== '' ? $node['@id'] : null;
        if ($id === null) {
            $this->nodes[] = $node;
        } elseif (isset($this->nodes[$id])) {
            # Array union: keys of the node already there win
            $this->nodes[$id] = $this->nodes[$id] + $node;
        } else {
            $this->nodes[$id] = $node;
        }
        return $this;
    }

    /**
     * Puts the node in place of the one with the same `@id` (or adds it), instead of merging.
     */
    public function replace(array $node): static
    {
        $id = isset($node['@id']) && is_string($node['@id']) && $node['@id'] !== '' ? $node['@id'] : null;
        if ($id === null) {
            return $this->add($node);
        }
        $this->nodes[$id] = $node;
        return $this;
    }

    /**
     * @param iterable<array> $nodes
     */
    public function addAll(iterable $nodes): static
    {
        foreach ($nodes as $node) {
            if (is_array($node) && $node) {
                $this->add($node);
            }
        }
        return $this;
    }

    public function has(string $id): bool
    {
        return isset($this->nodes[$id]);
    }

    public function get(string $id): ?array
    {
        return $this->nodes[$id] ?? null;
    }

    public function remove(string $id): static
    {
        unset($this->nodes[$id]);
        return $this;
    }

    public function isEmpty(): bool
    {
        return !$this->nodes;
    }

    /**
     * @return array[] The nodes in the order they were added
     */
    public function nodes(): array
    {
        return array_values($this->nodes);
    }

    /**
     * Replaces the nodes, keeping only the ones the callback accepts. Used by the guard that drops incomplete
     * nodes.
     *
     * @param callable(array):bool $keep
     */
    public function filter(callable $keep): static
    {
        $this->nodes = array_filter($this->nodes, $keep);
        return $this;
    }

    public function toArray(): array
    {
        return [
            '@context' => 'https://schema.org',
            '@graph' => $this->nodes(),
        ];
    }

    /**
     * The graph as JSON that is safe to place between `<script type="application/ld+json">` tags.
     *
     * A value JSON cannot hold (NAN or INF from a calculation, a resource, an object) is dropped with a warning
     * in the log, so one bad value costs that value, not the page's structured data. Invalid UTF-8 is
     * substituted. JSON_PARTIAL_OUTPUT_ON_ERROR is the last safety net; an empty string only if even that fails.
     */
    public function toJson(int $extraFlags = 0): string
    {
        $dropped = [];
        $data = $this->encodable($this->toArray(), '', $dropped);
        if ($dropped) {
            $this->logWarning('Structured data: values JSON cannot encode were left out: ' . implode(', ', $dropped));
        }
        $json = json_encode($data, static::JSON_FLAGS | JSON_PARTIAL_OUTPUT_ON_ERROR | $extraFlags);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->logWarning('Structured data: JSON encoding was partial: ' . json_last_error_msg());
        }
        return $json === false ? '' : $json;
    }

    /**
     * The data without the values json_encode() cannot represent; their paths are collected in $dropped.
     */
    protected function encodable(array $data, string $path, array &$dropped): array
    {
        foreach ($data as $key => $value) {
            $here = $path === '' ? (string) $key : $path . '.' . $key;
            if (is_array($value)) {
                $data[$key] = $this->encodable($value, $here, $dropped);
            } elseif ((is_float($value) && !is_finite($value))
                || is_resource($value)
                || (is_object($value) && !$value instanceof \JsonSerializable)
            ) {
                unset($data[$key]);
                $dropped[] = $here;
            }
        }
        # A list keeps its indexes consecutive after a removal, or it would be encoded as an object
        return $this->wasList($data) ? array_values($data) : $data;
    }

    /**
     * Whether the keys are integers (a list with a gap after a removal).
     */
    protected function wasList(array $data): bool
    {
        foreach (array_keys($data) as $key) {
            if (!is_int($key)) {
                return false;
            }
        }
        return true;
    }

    protected function logWarning(string $message): void
    {
        try {
            Injector::inst()->get(LoggerInterface::class)->warning($message);
        } catch (\Throwable) {
            # No logger configured: nothing to do
        }
    }

    /**
     * Removes references (`{"@id": ...}` and nothing else) to nodes that are not in the graph, for ids that start
     * with the prefix (the site's own base URL): a reference to the organisation when it was not emitted, or to a
     * node the guard dropped. References to other sites' ids are left alone. A property left empty is removed.
     *
     * @return bool Whether anything was removed; removing can make another node incomplete, so the caller may
     *              have to check again
     */
    public function pruneReferences(string $prefix): bool
    {
        # Nested nodes with an @id of their own (a place inside an event) are valid targets too
        $this->knownIds = [];
        foreach ($this->nodes as $node) {
            $this->collectIds($node);
        }
        $removed = false;
        foreach ($this->nodes as $key => $node) {
            $this->nodes[$key] = $this->pruneIn($node, $prefix, $removed);
        }
        return $removed;
    }

    /**
     * Ids of every described node, top-level or nested, while pruning.
     *
     * @var array<string,bool>
     */
    protected array $knownIds = [];

    protected function collectIds(array $data): void
    {
        if (isset($data['@id']) && is_string($data['@id']) && count($data) > 1) {
            $this->knownIds[$data['@id']] = true;
        }
        foreach ($data as $value) {
            if (is_array($value)) {
                $this->collectIds($value);
            }
        }
    }

    protected function pruneIn(array $data, string $prefix, bool &$removed): array
    {
        $isList = array_is_list($data);
        foreach ($data as $key => $value) {
            if (!is_array($value)) {
                continue;
            }
            if ($this->isDanglingReference($value, $prefix)) {
                unset($data[$key]);
                $removed = true;
                continue;
            }
            $value = $this->pruneIn($value, $prefix, $removed);
            if ($value === []) {
                # A list whose every entry was a dangling reference
                unset($data[$key]);
            } else {
                $data[$key] = $value;
            }
        }
        return $isList ? array_values($data) : $data;
    }

    protected function isDanglingReference(array $value, string $prefix): bool
    {
        return count($value) === 1
            && isset($value['@id'])
            && is_string($value['@id'])
            && str_starts_with($value['@id'], $prefix)
            && !isset($this->nodes[$value['@id']])
            && !isset($this->knownIds[$value['@id']]);
    }
}
