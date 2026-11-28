<?php

namespace Restruct\Silverstripe\SEO\Schema;

use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\Tab;
use SilverStripe\Forms\TabSet;
use SilverStripe\ORM\DataObject;
use SilverStripe\SiteConfig\SiteConfig;
use SilverStripe\SiteConfig\SiteConfigLeftAndMain;
use Throwable;

/**
 * The structured data of a page in an editor's words, for the SEO tab (product owner's call, 2026-11-28):
 *
 * - what search engines get from the page: one plain label per node ("Website", "Organisation", "Job posting")
 *   and, where there is one, what Google can show for it;
 * - each recommended property that is missing and that a CMS field fills, as an action naming the field and
 *   where it is ("Organisation: fill in Telephone under Settings > SEO."). The field is found through the
 *   `schema_map` of the record the node came from ({@see PageSchema::getSourceRecord()}): a database field or
 *   has_one of that record, also inside a nested map (an address) or a date or html entry. A property no field of
 *   the record fills (a constant, a relation to another record, a method, `provideSchemaData()`) is left to the
 *   developers, as is everything the registry says that is not about content.
 *
 * Required properties that are missing stay as the registry words them: the node is not output at all then, so
 * it is shown to everyone.
 */
class SchemaEditorSummary
{
    use Injectable;

    /**
     * The page fields the WebPage node is built from ({@see PageSchema}), by property.
     */
    protected const WEBPAGE_FIELDS = [
        'description' => 'MetaDescription',
        'name' => 'MetaTitle',
    ];

    /**
     * @param FieldList|null $pageFields The page's own edit form as it is being built, to find the tab of a page
     *                                   field (asking the page for its fields here would build them again)
     */
    public function __construct(
        protected PageSchema $schema,
        protected SiteTree $page,
        protected ?FieldList $pageFields = null
    ) {
    }

    /**
     * One entry per node in the graph, in graph order, one per label: [label, what Google can show or ''].
     *
     * @return array<int, array{0: string, 1: string}>
     */
    public function types(): array
    {
        $seen = [];
        $registry = SchemaTypeRegistry::singleton();
        foreach ($this->schema->getGraph()->nodes() as $node) {
            $type = $registry->typesOf($node)[0] ?? '';
            if ($type === '') {
                continue;
            }
            $known = $this->knownType($type);
            $label = $known ? static::typeLabel($known) : $type;
            $seen[$label] ??= [$label, $known ? static::typeBenefit($known) : ''];
        }
        return array_values($seen);
    }

    /**
     * "Organisation: fill in Telephone under Settings > SEO.", one per recommended gap a CMS field fills.
     *
     * @return string[]
     */
    public function actions(): array
    {
        $actions = [];
        foreach ($this->schema->getWarnings() as $warning) {
            if (($warning['severity'] ?? '') !== 'recommended' || !is_string($warning['id'] ?? null)) {
                continue;
            }
            $record = $this->schema->getSourceRecord($warning['id']);
            $property = (string) $warning['property'];
            if ($record) {
                $fields = $this->fieldsFor($record, $property);
            } elseif ($this->knownType(explode(', ', (string) $warning['type'])[0]) === 'WebPage' && isset(static::WEBPAGE_FIELDS[$property])) {
                # The WebPage node is built from the page's own fields, not from a map
                $record = $this->page;
                $fields = [static::WEBPAGE_FIELDS[$property]];
            } else {
                continue;
            }
            if (!$fields) {
                continue;
            }
            $action = $this->action($record, $fields, (string) $warning['type']);
            if ($action !== '') {
                $actions[$action] = true;
            }
        }
        return array_keys($actions);
    }

    /**
     * The messages of missing required properties: the node is not output.
     *
     * @return string[]
     */
    public function requiredMessages(): array
    {
        return array_values(array_unique(array_map(
            fn (array $w) => (string) $w['message'],
            array_filter($this->schema->getWarnings(), fn (array $w) => ($w['severity'] ?? '') === 'required')
        )));
    }

    /**
     * Every finding of the registry and the mapper, as worded for developers.
     *
     * @return string[]
     */
    public function developerMessages(): array
    {
        return array_values(array_unique(array_map(fn (array $w) => (string) $w['message'], $this->schema->getWarnings())));
    }

    /**
     * The nearest type with a label of its own, walking up the registry's parents (Corporation: Organization).
     */
    protected function knownType(string $type): ?string
    {
        $registry = SchemaTypeRegistry::singleton();
        $seen = [];
        while ($type !== '' && !isset($seen[$type])) {
            $seen[$type] = true;
            if (static::typeLabel($type) !== '') {
                return $type;
            }
            $type = (string) $registry->parentOf($type);
        }
        return null;
    }

    public static function typeLabel(string $type): string
    {
        return match ($type) {
            'WebSite' => _t(__CLASS__ . '.TypeWebSite', 'Website'),
            'Organization' => _t(__CLASS__ . '.TypeOrganization', 'Organisation'),
            'LocalBusiness' => _t(__CLASS__ . '.TypeLocalBusiness', 'Local business'),
            'WebPage' => _t(__CLASS__ . '.TypeWebPage', 'Web page'),
            'BreadcrumbList' => _t(__CLASS__ . '.TypeBreadcrumbList', 'Breadcrumb trail'),
            'ItemList' => _t(__CLASS__ . '.TypeItemList', 'List'),
            'Event' => _t(__CLASS__ . '.TypeEvent', 'Event'),
            'JobPosting' => _t(__CLASS__ . '.TypeJobPosting', 'Job posting'),
            'Course' => _t(__CLASS__ . '.TypeCourse', 'Course'),
            'CourseInstance' => _t(__CLASS__ . '.TypeCourseInstance', 'Course date'),
            'EducationalOccupationalProgram' => _t(__CLASS__ . '.TypeProgram', 'Study programme'),
            'Article' => _t(__CLASS__ . '.TypeArticle', 'Article'),
            'Place' => _t(__CLASS__ . '.TypePlace', 'Place'),
            default => '',
        };
    }

    /**
     * What Google can do with a node of this type, in an editor's words; '' when there is nothing to show.
     */
    public static function typeBenefit(string $type): string
    {
        return match ($type) {
            'WebSite' => _t(__CLASS__ . '.BenefitWebSite', 'Google can show the site name above the search result.'),
            'Organization', 'LocalBusiness' => _t(__CLASS__ . '.BenefitOrganization', 'Who is behind the site; Google can show its logo and contact details.'),
            'WebPage' => _t(__CLASS__ . '.BenefitWebPage', 'The page\'s title, description and language.'),
            'BreadcrumbList' => _t(__CLASS__ . '.BenefitBreadcrumbList', 'Google can show where the page is in the site instead of its web address.'),
            'ItemList' => _t(__CLASS__ . '.BenefitItemList', 'The items the page lists; Google can show some lists as a carousel.'),
            'Event' => _t(__CLASS__ . '.BenefitEvent', 'Google can show the event with its date and place in search results and event listings.'),
            'JobPosting' => _t(__CLASS__ . '.BenefitJobPosting', 'Google can show the vacancy in its job search.'),
            'Course' => _t(__CLASS__ . '.BenefitCourse', 'Google can show the course in course lists.'),
            'Article' => _t(__CLASS__ . '.BenefitArticle', 'Google can show the headline, image and date.'),
            default => '',
        };
    }

    /**
     * The fields of the record that fill a property (a dotted path for a nested one: `author.name`), through its
     * `schema_map`; empty when none does.
     *
     * @return string[]
     */
    protected function fieldsFor(DataObject $record, string $property): array
    {
        if ($record->hasMethod('provideSchemaData')) {
            return [];
        }
        $spec = (array) $record->config()->get('schema_map');
        foreach (explode('.', $property) as $part) {
            if (!is_array($spec) || array_is_list($spec) || !array_key_exists($part, $spec)) {
                return [];
            }
            $spec = $spec[$part];
        }
        return array_values(array_unique($this->leafFields($record, $spec)));
    }

    /**
     * @return string[]
     */
    protected function leafFields(DataObject $record, mixed $spec): array
    {
        if (is_string($spec)) {
            # Only the record's own field or has_one: a dotted path or a method is not something to fill in here
            if (str_contains($spec, '.')) {
                return [];
            }
            $schema = $record->getSchema();
            return $schema->fieldSpec($record, $spec) || $schema->hasOneComponent($record::class, $spec) ? [$spec] : [];
        }
        if (!is_array($spec)) {
            return [];
        }
        if (array_is_list($spec)) {
            return array_merge([], ...array_map(fn ($item) => $this->leafFields($record, $item), $spec));
        }
        if (isset($spec['literal']) || isset($spec['ref']) || isset($spec['from'])) {
            # A constant, a reference to another node, or another record's data
            return [];
        }
        if (isset($spec['date']) || isset($spec['html'])) {
            return array_merge(
                $this->leafFields($record, $spec['date'] ?? null),
                $this->leafFields($record, $spec['time'] ?? null),
                $this->leafFields($record, $spec['html'] ?? null)
            );
        }
        $fields = [];
        foreach ($spec as $key => $value) {
            if (is_string($key) && $key !== '' && $key[0] !== '@') {
                array_push($fields, ...$this->leafFields($record, $value));
            }
        }
        return $fields;
    }

    /**
     * @param string[] $fields
     */
    protected function action(DataObject $record, array $fields, string $type): string
    {
        $known = $this->knownType(explode(', ', $type)[0]);
        $typeLabel = $known ? static::typeLabel($known) : $type;
        $labels = implode(', ', array_map(fn ($name) => (string) $record->fieldLabel($name), $fields));

        if ($record instanceof SiteConfig) {
            $tab = $this->tabTitle($this->siteConfigFields($record), $fields[0]);
            $place = SiteConfigLeftAndMain::menu_title() . ($tab !== '' ? ' > ' . $tab : '');
            return _t(__CLASS__ . '.ActionSettings', '{type}: fill in {fields} under {place}.', [
                'type' => $typeLabel,
                'fields' => $labels,
                'place' => $place,
            ]);
        }
        if ($record instanceof SiteTree && (int) $record->ID === (int) $this->page->ID) {
            $tab = $this->pageFields ? $this->tabTitle($this->pageFields, $fields[0]) : '';
            return $tab !== ''
                ? _t(__CLASS__ . '.ActionPageTab', '{type}: fill in {fields} under {tab} on this page.', [
                    'type' => $typeLabel,
                    'fields' => $labels,
                    'tab' => $tab,
                ])
                : _t(__CLASS__ . '.ActionPage', '{type}: fill in {fields} on this page.', [
                    'type' => $typeLabel,
                    'fields' => $labels,
                ]);
        }
        return _t(__CLASS__ . '.ActionRecord', '{type}: fill in {fields} on {record} "{title}".', [
            'type' => $typeLabel,
            'fields' => $labels,
            'record' => $record->i18n_singular_name(),
            'title' => (string) $record->getTitle(),
        ]);
    }

    protected ?FieldList $siteConfigFields = null;

    protected function siteConfigFields(SiteConfig $config): ?FieldList
    {
        try {
            return $this->siteConfigFields ??= $config->getCMSFields();
        } catch (Throwable) {
            # Only for naming the tab: the action still names the field and the settings
            return null;
        }
    }

    /**
     * The title of the top-level tab that holds the field, '' when it is in none.
     */
    protected function tabTitle(?FieldList $fields, string $name): string
    {
        $root = $fields?->fieldByName('Root');
        if (!$root instanceof TabSet) {
            return '';
        }
        foreach ($root->Tabs() as $tab) {
            if ($tab instanceof Tab && $tab->getChildren()->dataFieldByName($name)) {
                return (string) $tab->Title();
            }
        }
        return '';
    }
}
