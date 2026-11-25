<?php

namespace Restruct\Silverstripe\SEO\Schema;

use DateTimeInterface;
use Restruct\Silverstripe\SEO\Extensions\SchemaSource;
use Restruct\Silverstripe\SEO\Text\DescriptionSummary;
use SilverStripe\Assets\Image;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Model\ArrayData;
use SilverStripe\Model\List\SS_List;
use SilverStripe\Model\ModelData;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\FieldType\DBBoolean;
use SilverStripe\ORM\FieldType\DBDate;
use SilverStripe\ORM\FieldType\DBField;
use SilverStripe\ORM\FieldType\DBHTMLText;
use SilverStripe\ORM\FieldType\DBHTMLVarchar;
use Throwable;

/**
 * Turns records into structured-data nodes.
 *
 * A record is a source in one of two ways:
 * - it has a method `provideSchemaData(SchemaContext $ctx): ?array` returning one node or a list of nodes (for
 *   anything a map cannot express, such as external API data cached on a page); this wins over a map;
 * - it has the {@see SchemaSource} extension with a `schema_type` and a `schema_map`.
 *
 * Either way, every node then goes through the `updateSchemaData(array &$node, SchemaContext $ctx)` extension
 * hook on the record.
 *
 * A `schema_map` value is one of:
 * - `FieldName`, `MethodName` or a dotted path through relations (`Venue.City`); a has_many or many_many on the
 *   way gives a list. Only database fields, relations, getters, methods in `casting` and methods listed in
 *   `schema_allowed_methods` are read (see isAllowed());
 * - `{ literal: value }`: the value as written;
 * - `{ ref: organization }`: a reference `{"@id": ".../#organization"}` to a site node (`website`, `organization`),
 *   or to a node of this page view (`webpage`, `breadcrumb`, `itemlist`); a full URL is used as it is;
 * - `{ date: DateField, time: TimeField }`: one ISO 8601 value with the site timezone's offset;
 * - a nested map, optionally with its own `@type` and a `from` relation to read from (a has_many or many_many
 *   gives a list of nested nodes);
 * - a YAML list of any of these: a list of values (`sameAs: [FacebookURL, LinkedInURL]`).
 *
 * Values are converted for JSON: Date and Datetime fields to ISO 8601, HTML to plain text, an Image to an
 * ImageObject (public images only), a related record that is itself a source to its node, a list to an array.
 * Empty values are left out, so a missing value never shows up as `""` or `null`.
 */
class SchemaMapper
{
    use Injectable;

    /**
     * How deep related sources are followed (an event's places, a place's address records, ...). Stops a loop
     * between two classes that map each other.
     */
    protected const MAX_DEPTH = 4;

    protected int $depth = 0;

    public function __construct(protected SchemaContext $context)
    {
    }

    public function getContext(): SchemaContext
    {
        return $this->context;
    }

    /**
     * Whether the record supplies structured data at all.
     */
    public static function isSource(DataObject $record): bool
    {
        return $record->hasMethod('provideSchemaData')
            || ($record->hasExtension(SchemaSource::class) && (string) $record->config()->get('schema_type') !== '');
    }

    /**
     * The record's nodes; an empty array when it is no source or provides nothing.
     *
     * @return array[]
     */
    public function nodesFor(DataObject $record): array
    {
        if ($this->depth >= static::MAX_DEPTH) {
            return [];
        }
        $this->depth++;
        try {
            if ($record->hasMethod('provideSchemaData')) {
                $data = $record->provideSchemaData($this->context);
                if (!is_array($data) || !$data) {
                    return [];
                }
                $nodes = array_is_list($data) ? $data : [$data];
                # Method data may hold fields, images or lists too: convert them like map values
                $nodes = array_map(fn ($node) => is_array($node) ? $this->normalise($node) : null, $nodes);
            } elseif (static::isSource($record)) {
                $type = (string) $record->config()->get('schema_type');
                $nodes = [$this->mapRecord(
                    $record,
                    $type,
                    (array) $record->config()->get('schema_map'),
                    $this->idFor($record, $type)
                )];
            } else {
                return [];
            }

            $result = [];
            $context = $this->context;
            foreach ($nodes as $node) {
                if (!is_array($node) || !$node) {
                    continue;
                }
                $record->extend('updateSchemaData', $node, $context);
                if ($node) {
                    $result[] = $node;
                }
            }
            return $result;
        } finally {
            $this->depth--;
        }
    }

    /**
     * The `@id` of a mapped record: a site node when the class sets `schema_id` (SiteConfig: `organization`),
     * otherwise the record rule of {@see SchemaContext::recordId()}.
     */
    public function idFor(DataObject $record, string $type): string
    {
        $siteId = (string) $record->config()->get('schema_id');
        return $siteId !== '' ? $this->context->siteId($siteId) : $this->context->recordId($record, $type);
    }

    /**
     * One node from a map.
     */
    public function mapRecord(ModelData $record, string $type, array $map, ?string $id = null): array
    {
        $node = $type !== '' ? ['@type' => $type] : [];
        if ($id) {
            $node['@id'] = $id;
        }
        foreach ($map as $property => $spec) {
            # Keys starting with @ are the map's own (@type of a nested map); they are not properties
            if (!is_string($property) || $property === '' || $property[0] === '@') {
                continue;
            }
            $value = $this->resolve($record, $spec);
            if (!static::isEmpty($value)) {
                $node[$property] = $value;
            }
        }
        return $node;
    }

    /**
     * The value of one map entry for a record.
     */
    public function resolve(ModelData $record, mixed $spec): mixed
    {
        if ($spec === null || $spec === '' || $spec === false || $spec === []) {
            # null switches off a property a project's YAML inherited from the module's default map
            return null;
        }
        if (is_string($spec)) {
            return $this->normalise($this->resolvePath($record, $spec));
        }
        if (!is_array($spec)) {
            # A number or true straight in the YAML: a literal
            return $spec;
        }
        if (array_is_list($spec)) {
            $values = [];
            foreach ($spec as $entry) {
                $value = $this->resolve($record, $entry);
                if (static::isEmpty($value)) {
                    continue;
                }
                # A list entry that is a list itself (a has_many) adds its items, so the result stays flat
                if (is_array($value) && array_is_list($value)) {
                    array_push($values, ...$value);
                } else {
                    $values[] = $value;
                }
            }
            return $values ?: null;
        }
        if (array_key_exists('literal', $spec)) {
            return $spec['literal'];
        }
        if (isset($spec['ref'])) {
            return ['@id' => $this->referenceId((string) $spec['ref'])];
        }
        if (isset($spec['date'])) {
            $time = isset($spec['time']) ? $this->resolvePath($record, (string) $spec['time']) : null;
            return $this->context->combineDateTime($this->resolvePath($record, (string) $spec['date']), $time);
        }
        return $this->resolveNested($record, $spec);
    }

    /**
     * Follows a field, method or dotted relation path. A list on the way (has_many, many_many) gives a list of
     * the values of each item. The raw value is returned (a DBField, a record, a list); see normalise().
     */
    public function resolvePath(ModelData $record, string $path): mixed
    {
        $parts = array_values(array_filter(explode('.', $path), fn ($part) => $part !== ''));
        $current = $record;
        foreach ($parts as $i => $part) {
            if ($current instanceof SS_List) {
                $rest = implode('.', array_slice($parts, $i));
                $values = [];
                foreach ($current as $item) {
                    $values[] = $item instanceof ModelData ? $this->resolvePath($item, $rest) : null;
                }
                return $values;
            }
            if (!$current instanceof ModelData) {
                return null;
            }
            # An empty has_one gives an unsaved record: there is nothing to read from it
            if ($i > 0 && $current instanceof DataObject && !$current->exists()) {
                return null;
            }
            $current = $this->readOne($current, $part);
        }
        return $current;
    }

    /**
     * Converts a value for JSON (see the class docblock). Empty values become null.
     */
    public function normalise(mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }
        # Date and Datetime (DBDatetime extends DBDate)
        if ($value instanceof DBDate) {
            return $this->context->formatDate($value);
        }
        if ($value instanceof DBHTMLText || $value instanceof DBHTMLVarchar) {
            $text = DescriptionSummary::plainText((string) $value->getValue());
            return $text !== '' ? $text : null;
        }
        if ($value instanceof DBBoolean) {
            return $value->getValue() === null ? null : (bool) $value->getValue();
        }
        if ($value instanceof DBField) {
            return $this->normalise($value->getValue());
        }
        if ($value instanceof Image) {
            return $this->context->imageObject($value);
        }
        if ($value instanceof DataObject) {
            if (!$value->exists()) {
                return null;
            }
            # A related record that is a source itself is described by its own map
            return $this->nodesFor($value)[0] ?? null;
        }
        if ($value instanceof SS_List) {
            return $this->normalise($value->toArray());
        }
        if ($value instanceof ArrayData) {
            return $this->normalise($value->toMap());
        }
        if ($value instanceof DateTimeInterface) {
            return $this->context->formatDate($value);
        }
        if ($value instanceof ModelData) {
            # Something template-only (a form, a controller): not data
            return null;
        }
        if (is_array($value)) {
            $isList = array_is_list($value);
            $out = [];
            foreach ($value as $key => $item) {
                $item = $this->normalise($item);
                if (!static::isEmpty($item)) {
                    $out[$key] = $item;
                }
            }
            if (!$out) {
                return null;
            }
            return $isList ? array_values($out) : $out;
        }
        if (is_string($value)) {
            $value = trim($value);
            return $value === '' ? null : $value;
        }
        return is_scalar($value) ? $value : null;
    }

    public static function isEmpty(mixed $value): bool
    {
        return $value === null || $value === '' || $value === [];
    }

    /**
     * A nested map: its own `@type`, optionally a `from` relation (a list gives a list of nodes).
     */
    protected function resolveNested(ModelData $record, array $spec): mixed
    {
        $type = isset($spec['@type']) ? (string) $spec['@type'] : '';
        $map = array_diff_key($spec, ['@type' => true, 'from' => true]);

        if (!isset($spec['from'])) {
            return $this->nestedNode($record, $type, $map);
        }
        $target = $this->resolvePath($record, (string) $spec['from']);
        if ($target instanceof SS_List || is_array($target)) {
            $nodes = [];
            foreach ($target as $item) {
                if ($item instanceof ModelData && ($node = $this->nestedNode($item, $type, $map))) {
                    $nodes[] = $node;
                }
            }
            return $nodes ?: null;
        }
        # An empty has_one gives an unsaved record: no node
        if ($target instanceof DataObject && !$target->exists()) {
            return null;
        }
        return $target instanceof ModelData ? $this->nestedNode($target, $type, $map) : null;
    }

    protected function nestedNode(ModelData $record, string $type, array $map): ?array
    {
        $node = $this->mapRecord($record, $type, $map);
        # Only a type and no properties says nothing
        return array_diff_key($node, ['@type' => true]) ? $node : null;
    }

    /**
     * The `@id` behind `{ ref: ... }`.
     */
    protected function referenceId(string $ref): string
    {
        if (str_contains($ref, '://')) {
            return $ref;
        }
        if (in_array($ref, ['webpage', 'breadcrumb', 'itemlist'], true)) {
            return $this->context->pageId($ref);
        }
        return $this->context->siteId($ref);
    }

    /**
     * Whether a map may read this name on the record: a database field (ID, Created and LastEdited included), a
     * relation, a `get<Name>()` getter, a method declared in `casting` (so it is meant to be read as a value), or
     * a method listed in the class's `schema_allowed_methods`. Anything else is never called: a map is
     * configuration, and a typo or a careless map (`delete`, `write`, `publishRecursive`) must not run a method
     * with side effects on every page view.
     */
    protected function isAllowed(ModelData $record, string $name): bool
    {
        if ($record instanceof DataObject) {
            if ($record->getSchema()->fieldSpec(get_class($record), $name) || $record->getRelationType($name) !== null) {
                return true;
            }
        } elseif ($record->hasField($name)) {
            # ArrayData and similar: its keys are its data
            return true;
        }
        # getField() is no getter of a field called "Field" (ModelData::__get makes the same exception)
        if (strtolower($name) !== 'field' && $record->hasMethod('get' . $name)) {
            return true;
        }
        if (array_key_exists($name, (array) $record->config()->get('casting'))) {
            return true;
        }
        return in_array($name, (array) $record->config()->get('schema_allowed_methods'), true);
    }

    /**
     * Problems found while mapping that are not about a node's properties: a name the map may not call.
     *
     * @return array<int,array{code:string,class:string,property:string}>
     */
    public function getNotices(): array
    {
        return array_values($this->notices);
    }

    /**
     * @var array<string,array{code:string,class:string,property:string}>
     */
    protected array $notices = [];

    protected function notice(ModelData $record, string $property, string $code): void
    {
        $class = get_class($record);
        # Once per class and name, however many records are mapped
        $this->notices[$code . ' ' . $class . ' ' . $property] = [
            'code' => $code,
            'class' => $class,
            'property' => $property,
        ];
    }

    /**
     * One step of a path, read the way a template reads it: through obj(), so a method wins, then a `get<Name>()`
     * getter (an overriding getTitle() included), then the field; and the result is cast by the record's
     * `casting` or the column type, so a method cast as HTMLText is recognised as HTML and a Date column as a
     * date. Null when nothing on the record backs the name.
     */
    protected function readOne(ModelData $record, string $name): mixed
    {
        if (!$this->isAllowed($record, $name)) {
            $this->notice($record, $name, 'method-not-allowed');
            return null;
        }
        try {
            return $record->obj($name);
        } catch (Throwable) {
            # A getter that needs arguments, or a casting class that rejects the value: no value
            return null;
        }
    }
}
