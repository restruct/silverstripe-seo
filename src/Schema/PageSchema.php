<?php

namespace Restruct\Silverstripe\SEO\Schema;

use Psr\Log\LoggerInterface;
use Restruct\Silverstripe\SEO\Model\SubjectMetadata;
use SilverStripe\CMS\Controllers\RootURLController;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\i18n\i18n;
use SilverStripe\Model\List\SS_List;
use SilverStripe\ORM\DataObject;
use Throwable;

/**
 * Builds the structured-data graph of one page view:
 *
 * 1. WebSite (`<base>/#website`) and the Organization mapped from SiteConfig (`<base>/#organization`);
 * 2. the WebPage (`#webpage`), with the type set per page class in `schema_webpage_type`;
 * 3. a BreadcrumbList (`#breadcrumb`) from the page's breadcrumb trail, with the home page in front and a shown
 *    record at the end; left out when the trail has fewer than two entries (the home page itself);
 * 4. the content source's own nodes: the record the page shows (its SEO subject) or else the page itself; the
 *    first of them is the WebPage's `mainEntity`;
 * 5. the records the content source lists in `schema_includes`, as nodes of their own;
 * 6. on the page itself (not on a shown record), an ItemList (`#itemlist`) of the relation or method named in
 *    `schema_item_list`; it is the main entity when nothing else is;
 * 7. the page's `updateSeoSchemaGraph(SchemaGraph $graph, SchemaContext $ctx)` hook.
 *
 * Then the guard: a node lacking a required property of its type ({@see SchemaTypeRegistry}) is left out, and
 * references to it are removed, which can make another node incomplete in turn (a JobPosting whose
 * hiringOrganization was not output); this repeats until nothing changes. What was left out, and what is
 * recommended but missing, is available from getWarnings().
 */
class PageSchema
{
    use Injectable;

    protected ?SchemaGraph $graph = null;

    protected array $warnings = [];

    public function __construct(
        protected SiteTree $page,
        protected ?DataObject $subject = null
    ) {
    }

    public function getGraph(): SchemaGraph
    {
        if ($this->graph === null) {
            $this->build();
        }
        return $this->graph;
    }

    /**
     * What the type registry has to say about the nodes of this page view, one entry per missing property:
     * `type`, `id`, `property`, `severity` (`required` or `recommended`), `emitted` (false when the node was left
     * out for it) and a readable `message`.
     *
     * @return array<int,array{type:string,id:?string,property:string,severity:string,emitted:bool,message:string}>
     */
    public function getWarnings(): array
    {
        $this->getGraph();
        return $this->warnings;
    }

    protected function build(): void
    {
        $page = $this->page;
        $context = SchemaContext::create($page, $this->subject);
        $mapper = SchemaMapper::create($context);
        $graph = SchemaGraph::create();
        $this->warnings = [];

        # 1. Site nodes
        $siteConfig = $page->getSiteConfig();
        $graph->add(array_filter([
            '@type' => 'WebSite',
            '@id' => $context->siteId('website'),
            'url' => $context->baseURL() . '/',
            'name' => trim((string) $siteConfig?->Title),
            'inLanguage' => $this->language(),
            'publisher' => ['@id' => $context->siteId('organization')],
        ], fn ($value) => $value !== '' && $value !== null));
        if ($siteConfig instanceof DataObject) {
            $graph->addAll($mapper->nodesFor($siteConfig));
        }

        # 4 is built before 2, because the WebPage points at the main entity
        $source = $this->subject ?? $page;
        $mainNodes = $mapper->nodesFor($source);
        $mainId = null;
        foreach ($mainNodes as $node) {
            if (isset($node['@id'])) {
                $mainId = $node['@id'];
                break;
            }
        }

        $itemList = $this->subject ? null : $this->itemList($context, $mapper);
        $mainId ??= $itemList['@id'] ?? null;
        $breadcrumb = $this->breadcrumb($context);

        # 2. The page
        $subjectMeta = $this->subject ? SubjectMetadata::create($this->subject, $page) : null;
        $webPage = [
            '@type' => (string) $page->config()->get('schema_webpage_type') ?: 'WebPage',
            '@id' => $context->pageId('webpage'),
            'url' => $context->pageURL(),
            'name' => $subjectMeta ? $subjectMeta->title() : trim((string) ($page->MetaTitle ?: $page->Title)),
            'description' => $subjectMeta ? $subjectMeta->description() : (string) $page->SeoDescription(),
            'isPartOf' => ['@id' => $context->siteId('website')],
            'inLanguage' => $this->language(),
            'breadcrumb' => $breadcrumb ? ['@id' => $breadcrumb['@id']] : null,
            'mainEntity' => $mainId ? ['@id' => $mainId] : null,
        ];
        $graph->add(array_filter($webPage, fn ($value) => !SchemaMapper::isEmpty($value)));

        # 3, 4, 5, 6
        if ($breadcrumb) {
            $graph->add($breadcrumb);
        }
        $graph->addAll($mainNodes);
        foreach ((array) $source->config()->get('schema_includes') as $name) {
            foreach ($this->records($mapper->resolvePath($source, (string) $name)) as $record) {
                $graph->addAll($mapper->nodesFor($record));
            }
        }
        if ($itemList) {
            $graph->add($itemList);
        }

        # 7
        $page->extend('updateSeoSchemaGraph', $graph, $context);

        $this->guard($graph, $context);
        $this->graph = $graph;
    }

    /**
     * Leaves out incomplete nodes and the references to them, until the graph is stable.
     */
    protected function guard(SchemaGraph $graph, SchemaContext $context): void
    {
        $registry = SchemaTypeRegistry::singleton();
        $resolve = fn (string $id): ?array => $graph->get($id);
        $prefix = $context->baseURL() . '/';

        # Each round can only remove nodes, so this ends; the cap is a safety net
        for ($round = 0; $round < 10; $round++) {
            $dropped = false;
            $graph->filter(function (array $node) use ($registry, $resolve, &$dropped): bool {
                $missing = $registry->missingRequired($node, $resolve);
                if (!$missing) {
                    return true;
                }
                $dropped = true;
                foreach ($missing as $property) {
                    $this->warn($node, $property, 'required', false);
                }
                $this->logSkipped($node, $missing);
                return false;
            });
            $pruned = $graph->pruneReferences($prefix);
            if (!$dropped && !$pruned) {
                break;
            }
        }

        foreach ($graph->nodes() as $node) {
            foreach ($registry->missingRecommended($node, $resolve) as $property) {
                $this->warn($node, $property, 'recommended', true);
            }
        }
    }

    protected function warn(array $node, string $property, string $severity, bool $emitted): void
    {
        $type = implode(', ', SchemaTypeRegistry::singleton()->typesOf($node)) ?: 'Thing';
        $message = $severity === 'required'
            ? _t(__CLASS__ . '.MissingRequired', '{type} lacks {property}, which is required: it is not output.', [
                'type' => $type,
                'property' => $property,
            ])
            : _t(__CLASS__ . '.MissingRecommended', '{type} lacks {property}, which is recommended.', [
                'type' => $type,
                'property' => $property,
            ]);
        $this->warnings[] = [
            'type' => $type,
            'id' => isset($node['@id']) && is_string($node['@id']) ? $node['@id'] : null,
            'property' => $property,
            'severity' => $severity,
            'emitted' => $emitted,
            'message' => $message,
        ];
    }

    /**
     * Debug level: an incomplete node is a content matter, not an error; the CMS and the checks report it.
     */
    protected function logSkipped(array $node, array $missing): void
    {
        try {
            Injector::inst()->get(LoggerInterface::class)->debug(sprintf(
                'Structured data: %s %s left out, missing %s',
                implode(', ', SchemaTypeRegistry::singleton()->typesOf($node)) ?: 'node',
                $node['@id'] ?? '(no @id)',
                implode(', ', $missing)
            ));
        } catch (Throwable) {
            # No logger configured: nothing to do
        }
    }

    protected function breadcrumb(SchemaContext $context): ?array
    {
        $page = $this->page;
        $entries = [];
        $trail = $page->getBreadcrumbItems()->toArray();

        # The home page in front, unless the trail already starts with it (a page below "home")
        $home = SiteTree::get_by_link(RootURLController::get_homepage_link());
        if ($home && (!$trail || (int) $trail[0]->ID !== (int) $home->ID)) {
            array_unshift($trail, $home);
        }
        foreach ($trail as $crumb) {
            $entries[] = [
                'name' => trim((string) ($crumb->MenuTitle ?: $crumb->Title)),
                'item' => $context->recordURL($crumb),
            ];
        }
        if ($this->subject) {
            $entries[] = [
                'name' => SubjectMetadata::create($this->subject, $page)->title(),
                'item' => $context->pageURL(),
            ];
        }
        if (count($entries) < 2) {
            return null;
        }

        $items = [];
        foreach ($entries as $i => $entry) {
            $items[] = ['@type' => 'ListItem', 'position' => $i + 1, 'name' => $entry['name'], 'item' => $entry['item']];
        }
        return [
            '@type' => 'BreadcrumbList',
            '@id' => $context->pageId('breadcrumb'),
            'itemListElement' => $items,
        ];
    }

    protected function itemList(SchemaContext $context, SchemaMapper $mapper): ?array
    {
        $name = (string) $this->page->config()->get('schema_item_list');
        if ($name === '') {
            return null;
        }
        $limit = (int) $this->page->config()->get('schema_item_list_limit');
        $list = $mapper->resolvePath($this->page, $name);
        if ($limit > 0 && $list instanceof SS_List && method_exists($list, 'limit')) {
            # Read only as many rows as are output
            $list = $list->limit($limit);
        }

        $items = [];
        foreach ($this->records($list) as $record) {
            $items[] = ['@type' => 'ListItem', 'position' => count($items) + 1, 'url' => $context->recordURL($record)];
            if ($limit > 0 && count($items) >= $limit) {
                break;
            }
        }
        if (!$items) {
            return null;
        }
        return [
            '@type' => 'ItemList',
            '@id' => $context->pageId('itemlist'),
            'itemListElement' => $items,
        ];
    }

    /**
     * The records in a resolved value: one record, a list, or a list of lists from a dotted path.
     *
     * @return DataObject[]
     */
    protected function records(mixed $value): array
    {
        if ($value instanceof DataObject) {
            return $value->exists() ? [$value] : [];
        }
        $records = [];
        if ($value instanceof SS_List || is_array($value)) {
            foreach ($value as $item) {
                array_push($records, ...$this->records($item));
            }
        }
        return $records;
    }

    /**
     * The content language as a BCP 47 tag (`en-US`).
     */
    protected function language(): string
    {
        return str_replace('_', '-', (string) i18n::get_locale());
    }
}
