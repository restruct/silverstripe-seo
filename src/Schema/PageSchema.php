<?php

namespace Restruct\Silverstripe\SEO\Schema;

use Psr\Log\LoggerInterface;
use Restruct\Silverstripe\SEO\Model\SubjectMetadata;
use SilverStripe\CMS\Controllers\RootURLController;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Core\ClassInfo;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\i18n\i18n;
use SilverStripe\Model\List\SS_List;
use SilverStripe\ORM\DataList;
use SilverStripe\ORM\DataObject;
use Throwable;

/**
 * Builds the structured-data graph of one page view:
 *
 * 1. WebSite (`<base>/#website`) and the Organization mapped from SiteConfig (`<base>/#organization`);
 * 2. the WebPage (`#webpage`), with the type set per page class in `schema_webpage_type`;
 * 3. a BreadcrumbList (`#breadcrumb`) from the page's breadcrumb trail, with the home page in front and a shown
 *    record at the end; left out when the trail has fewer than two entries (the home page itself);
 * 4. the content source's own nodes: the record the page shows (its SEO subject) or else the page itself;
 * 5. the records the content source lists in `schema_includes`, as nodes of their own, at most
 *    `schema_includes_limit` of them;
 * 6. on the page itself (not on a shown record), an ItemList (`#itemlist`) of the relation or method named in
 *    `schema_item_list`;
 * 7. the page's `updateSeoSchemaGraph(SchemaGraph $graph, SchemaContext $ctx)` hook.
 *
 * Then the guard: a node lacking a required property of its type ({@see SchemaTypeRegistry}) is left out, and
 * references to it are removed, which can make another node incomplete in turn (a JobPosting whose
 * hiringOrganization was not output); this repeats until nothing changes. Only then is the WebPage's
 * `mainEntity` chosen, from what is left: the first node of the content source, else the ItemList (unless the
 * hook set one already). What was left out, and what is
 * recommended but missing, is available from getWarnings().
 */
class PageSchema
{
    use Injectable;

    protected ?SchemaGraph $graph = null;

    protected array $warnings = [];

    /**
     * The record each mapped node came from, by `@id`: the site's Organization from SiteConfig, the content
     * source's nodes from it, included records from themselves. Read by the CMS summary to name the field that
     * fills a gap ({@see SchemaEditorSummary}).
     *
     * @var array<string, DataObject>
     */
    protected array $sources = [];

    /**
     * The content locale (`en_US`); null for the current i18n locale, which is the content's on the front end.
     */
    protected ?string $locale = null;

    public function __construct(
        protected SiteTree $page,
        protected ?DataObject $subject = null
    ) {
    }

    /**
     * Builds for another locale than the current one: the CMS runs in the editor's own language, while the
     * structured data describes the content.
     */
    public function setLocale(?string $locale): static
    {
        $this->locale = $locale;
        $this->graph = null;
        return $this;
    }

    public function getGraph(): SchemaGraph
    {
        if ($this->graph === null) {
            $this->build();
        }
        return $this->graph;
    }

    /**
     * What the type registry and the mapper have to say about the nodes of this page view, one entry per
     * finding: `type`, `id`, `property`, `severity` (`required`, `recommended` or `notice`), `emitted` (false
     * when the node was left out for it), a readable `message`, and a `code`: `missing-required`,
     * `missing-recommended`, `method-not-allowed` (a map names something it may not call), `type-not-registered`
     * (the node is output, but nothing about it could be checked).
     *
     * @return array<int,array{type:string,id:?string,property:string,severity:string,emitted:bool,message:string,code:string}>
     */
    public function getWarnings(): array
    {
        $this->getGraph();
        return $this->warnings;
    }

    /**
     * The record a node of this page view was mapped from, by its `@id`; null for a node built here (WebSite,
     * WebPage, breadcrumb, list) or by the page's hook.
     */
    public function getSourceRecord(string $id): ?DataObject
    {
        $this->getGraph();
        return $this->sources[$id] ?? null;
    }

    /**
     * Notes which record the nodes came from, and returns them.
     *
     * @param array[] $nodes
     * @return array[]
     */
    protected function fromRecord(DataObject $record, array $nodes): array
    {
        foreach ($nodes as $node) {
            if (isset($node['@id']) && is_string($node['@id'])) {
                $this->sources[$node['@id']] ??= $record;
            }
        }
        return $nodes;
    }

    protected function build(): void
    {
        $page = $this->page;
        $context = SchemaContext::create($page, $this->subject);
        $mapper = SchemaMapper::create($context);
        $graph = SchemaGraph::create();
        $this->warnings = [];
        $this->sources = [];

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
            $graph->addAll($this->fromRecord($siteConfig, $mapper->nodesFor($siteConfig)));
        }

        # 4, with the candidates for the main entity, in order of preference
        $source = $this->subject ?? $page;
        $mainNodes = $this->fromRecord($source, $mapper->nodesFor($source));
        $itemList = $this->subject ? null : $this->itemList($context, $mapper);
        $candidates = array_values(array_filter(array_map(
            fn (array $node) => isset($node['@id']) && is_string($node['@id']) ? $node['@id'] : null,
            array_merge($mainNodes, $itemList ? [$itemList] : [])
        )));
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
        ];
        $graph->add(array_filter($webPage, fn ($value) => !SchemaMapper::isEmpty($value)));

        # 3, 4, 5, 6
        if ($breadcrumb) {
            $graph->add($breadcrumb);
        }
        $graph->addAll($mainNodes);
        # Every included record is mapped on every page view (nothing is cached), so their number is capped
        $limit = (int) $source->config()->get('schema_includes_limit');
        $included = 0;
        foreach ((array) $source->config()->get('schema_includes') as $name) {
            $value = $mapper->resolvePath($source, (string) $name);
            if ($value instanceof DataList) {
                $value = $mapper->prepareList($value, $limit > 0 ? $limit - $included : 0);
            }
            foreach ($this->records($value, $mapper) as $record) {
                if ($limit > 0 && $included >= $limit) {
                    break 2;
                }
                $graph->addAll($this->fromRecord($record, $mapper->nodesFor($record)));
                $included++;
            }
        }
        if ($itemList) {
            $graph->add($itemList);
        }

        # 7
        $page->extend('updateSeoSchemaGraph', $graph, $context);

        $this->guard($graph, $context);
        $this->chooseMainEntity($graph, $context->pageId('webpage'), $candidates);
        foreach ($mapper->getNotices() as $notice) {
            $this->warnings[] = [
                'type' => ClassInfo::shortName($notice['class']),
                'id' => null,
                'property' => $notice['property'],
                'severity' => 'notice',
                'emitted' => true,
                'message' => _t(
                    __CLASS__ . '.MethodNotAllowed',
                    'The structured-data map of {class} names {property}, which is not a field, relation, getter or allowed method: it is not read. Add it to schema_allowed_methods if it is safe to call.',
                    ['class' => ClassInfo::shortName($notice['class']), 'property' => $notice['property']]
                ),
                'code' => $notice['code'],
            ];
        }
        $this->graph = $graph;
    }

    /**
     * Points the WebPage at the first candidate the guard kept: a main node left out for missing data no longer
     * hides the ItemList behind it.
     *
     * @param string[] $candidates
     */
    protected function chooseMainEntity(SchemaGraph $graph, string $webPageId, array $candidates): void
    {
        $webPage = $graph->get($webPageId);
        if (!$webPage || isset($webPage['mainEntity'])) {
            return;
        }
        foreach ($candidates as $id) {
            if ($graph->has($id)) {
                $webPage['mainEntity'] = ['@id' => $id];
                $graph->replace($webPage);
                return;
            }
        }
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
            # Nothing can be checked for a type the registry does not know: say so, the node is output anyway
            foreach ($registry->typesOf($node) as $type) {
                if (!$registry->isKnown($type)) {
                    $this->warnings[] = [
                        'type' => $type,
                        'id' => isset($node['@id']) && is_string($node['@id']) ? $node['@id'] : null,
                        'property' => '@type',
                        'severity' => 'notice',
                        'emitted' => true,
                        'message' => _t(
                            __CLASS__ . '.TypeNotRegistered',
                            '{type} is not in the type registry, so its required properties are not checked. Register it (with a parent type) in SchemaTypeRegistry.types.',
                            ['type' => $type]
                        ),
                        'code' => 'type-not-registered',
                    ];
                }
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
            'code' => 'missing-' . $severity,
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
        foreach ($this->records($list, $mapper) as $record) {
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
     * The records in a resolved value (one record, a list, or a list of lists from a dotted path) that an
     * anonymous visitor may see ({@see SchemaMapper::isVisible()}).
     *
     * @return DataObject[]
     */
    protected function records(mixed $value, SchemaMapper $mapper): array
    {
        if ($value instanceof DataObject) {
            return $value->exists() && $mapper->isVisible($value) ? [$value] : [];
        }
        $records = [];
        if ($value instanceof SS_List || is_array($value)) {
            foreach ($value as $item) {
                array_push($records, ...$this->records($item, $mapper));
            }
        }
        return $records;
    }

    /**
     * The content language as a BCP 47 tag (`en-US`). A translation module sets the page's own locale through
     * the page's `updateSeoSchemaLocale(string &$locale)` hook.
     */
    protected function language(): string
    {
        $locale = (string) ($this->locale ?? i18n::get_locale());
        $this->page->extend('updateSeoSchemaLocale', $locale);
        return str_replace('_', '-', $locale);
    }
}
