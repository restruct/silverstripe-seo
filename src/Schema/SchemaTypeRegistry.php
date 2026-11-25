<?php

namespace Restruct\Silverstripe\SEO\Schema;

use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Injector\Injectable;

/**
 * Required and recommended properties per schema.org type, after Google's structured-data documentation.
 *
 * Used for two things:
 * - the guard: a node missing a required property is not output at all, because Google ignores it (or reports
 *   it as an error in Search Console) and an incomplete node says nothing true about the page;
 * - warnings: missing required and recommended properties, shown in the CMS and read by the page checks.
 *
 * A property may be a dotted path (`location.address`): when the first part is a list, every entry must have
 * the rest. A reference (`{"@id": ...}`) is followed when a resolver is given, and taken on trust otherwise.
 *
 * Projects add types or properties in YAML; a type inherits the lists of its `parent`:
 *
 * ```yml
 * Restruct\Silverstripe\SEO\Schema\SchemaTypeRegistry:
 *   types:
 *     Occupation:
 *       required: [name]
 *       recommended: [occupationalCategory, estimatedSalary]
 * ```
 *
 * Retired rich result types (FAQPage, HowTo, Course Info, Estimated salary, ...) are deliberately not shipped.
 */
class SchemaTypeRegistry
{
    use Configurable;
    use Injectable;

    /**
     * Sources: developers.google.com/search/docs/appearance/structured-data/<page> and .../appearance/site-names,
     * read on 2026-11-25. None of the pages shows a "last updated" date. Where Google requires nothing, the
     * module requires the one property without which the node identifies nothing (marked "module minimum").
     */
    private static array $types = [
        # organization: "There are no required properties"; name is the module minimum, so an unnamed
        # organisation (a site without a title) is never output and nothing points at an empty node
        'Organization' => [
            'required' => ['name'],
            'recommended' => [
                'url', 'logo', 'description', 'address', 'telephone', 'email', 'sameAs', 'legalName', 'contactPoint',
            ],
        ],
        # schema.org subtypes, so the organisation's name stays required when a site picks a more specific type
        'Corporation' => ['parent' => 'Organization'],
        'EducationalOrganization' => ['parent' => 'Organization'],
        'CollegeOrUniversity' => ['parent' => 'EducationalOrganization'],
        'School' => ['parent' => 'EducationalOrganization'],
        'GovernmentOrganization' => ['parent' => 'Organization'],
        'NGO' => ['parent' => 'Organization'],
        'OnlineBusiness' => ['parent' => 'Organization'],
        # local-business
        'LocalBusiness' => [
            'parent' => 'Organization',
            'required' => ['name', 'address'],
            'recommended' => ['geo', 'openingHoursSpecification', 'telephone', 'url', 'priceRange', 'image'],
        ],
        # appearance/site-names
        'WebSite' => [
            'required' => ['name', 'url'],
            'recommended' => ['alternateName'],
        ],
        # No Google page; url is the module minimum. The subtypes below are schema.org's own
        'WebPage' => [
            'required' => ['url'],
            'recommended' => ['name', 'description', 'isPartOf', 'inLanguage'],
        ],
        'AboutPage' => ['parent' => 'WebPage'],
        'CheckoutPage' => ['parent' => 'WebPage'],
        'CollectionPage' => ['parent' => 'WebPage'],
        'ContactPage' => ['parent' => 'WebPage'],
        'ItemPage' => ['parent' => 'WebPage'],
        'ProfilePage' => ['parent' => 'WebPage'],
        'SearchResultsPage' => ['parent' => 'WebPage'],
        # breadcrumb: ListItem needs position and name; item may be left out on the last entry
        'BreadcrumbList' => [
            'required' => ['itemListElement', 'itemListElement.position', 'itemListElement.name'],
        ],
        # course (summary list) and carousel: ListItem position, and url or item
        'ItemList' => [
            'required' => ['itemListElement', 'itemListElement.position'],
        ],
        # event
        'Event' => [
            'required' => ['name', 'startDate', 'location', 'location.address'],
            'recommended' => [
                'description', 'endDate', 'eventStatus', 'image', 'location.name', 'offers', 'organizer',
                'performer', 'previousStartDate',
            ],
        ],
        'EducationEvent' => ['parent' => 'Event'],
        'BusinessEvent' => ['parent' => 'Event'],
        'ExhibitionEvent' => ['parent' => 'Event'],
        'Festival' => ['parent' => 'Event'],
        'MusicEvent' => ['parent' => 'Event'],
        'SocialEvent' => ['parent' => 'Event'],
        'SportsEvent' => ['parent' => 'Event'],
        'TheaterEvent' => ['parent' => 'Event'],
        # job-posting
        'JobPosting' => [
            'required' => ['datePosted', 'description', 'hiringOrganization', 'jobLocation', 'title'],
            'recommended' => [
                'applicantLocationRequirements', 'baseSalary', 'directApply', 'employmentType', 'identifier',
                'jobLocationType', 'validThrough',
            ],
        ],
        # course (the course list rich result, still listed)
        'Course' => [
            'required' => ['name', 'description'],
            'recommended' => ['provider', 'hasCourseInstance', 'offers'],
        ],
        # Not on Google's current course page: Course Info, which documented CourseInstance, was retired. From
        # the retired page and schema.org, best knowledge; nothing required
        'CourseInstance' => [
            'required' => [],
            'recommended' => ['courseMode', 'startDate', 'endDate', 'location', 'courseSchedule', 'courseWorkload', 'instructor', 'offers'],
        ],
        # The education-programs page returns 404 on 2026-11-25 and the type is gone from the search gallery: no
        # rich result any more. Kept for projects that describe programmes; name is the module minimum, the rest
        # from the retired page and schema.org, best knowledge
        'EducationalOccupationalProgram' => [
            'required' => ['name'],
            'recommended' => [
                'description', 'provider', 'url', 'timeToComplete', 'occupationalCategory',
                'educationalCredentialAwarded', 'occupationalCredentialAwarded', 'programPrerequisites', 'offers',
                'startDate', 'applicationDeadline',
            ],
        ],
        # article: "There are no required properties"; headline is the module minimum
        'Article' => [
            'required' => ['headline'],
            'recommended' => ['author', 'author.name', 'datePublished', 'dateModified', 'image'],
        ],
        'NewsArticle' => ['parent' => 'Article'],
        'BlogPosting' => ['parent' => 'Article'],
        # Used inside Event, JobPosting and LocalBusiness, which set the requirements; standing alone nothing is
        # required
        'Place' => [
            'required' => [],
            'recommended' => ['name', 'address', 'geo'],
        ],
        'PostalAddress' => [
            'required' => [],
            'recommended' => ['streetAddress', 'addressLocality', 'postalCode', 'addressCountry'],
        ],
        # local-business: "precision must be at least 5 decimal places"
        'GeoCoordinates' => [
            'required' => ['latitude', 'longitude'],
        ],
        # image-license-metadata: contentUrl, plus at least one of creator, creditText, copyrightNotice and
        # license for the licence details; that "one of" is not expressible here, so those four are recommended.
        # url is accepted as well; the module always sets both
        'ImageObject' => [
            'required' => ['contentUrl'],
            'recommended' => ['creator', 'creditText', 'copyrightNotice', 'license', 'acquireLicensePage'],
        ],
    ];

    public function isKnown(string $type): bool
    {
        return isset($this->types()[$type]);
    }

    /**
     * @param string|string[] $type One type or several (a node's `@type` may be a list)
     * @return string[]
     */
    public function required(string|array $type): array
    {
        return $this->collect($type, 'required');
    }

    /**
     * @param string|string[] $type
     * @return string[]
     */
    public function recommended(string|array $type): array
    {
        return $this->collect($type, 'recommended');
    }

    /**
     * The required properties the node lacks, in registry order.
     *
     * @param callable(string):?array|null $resolve Looks up a referenced node by its `@id`
     * @return string[]
     */
    public function missingRequired(array $node, ?callable $resolve = null): array
    {
        return $this->missing($node, $this->required($this->typesOf($node)), $resolve);
    }

    /**
     * @param callable(string):?array|null $resolve
     * @return string[]
     */
    public function missingRecommended(array $node, ?callable $resolve = null): array
    {
        return $this->missing($node, $this->recommended($this->typesOf($node)), $resolve);
    }

    /**
     * @return string[]
     */
    public function typesOf(array $node): array
    {
        $type = $node['@type'] ?? [];
        return array_values(array_filter((array) $type, 'is_string'));
    }

    /**
     * @param string[] $properties
     * @return string[]
     */
    protected function missing(array $node, array $properties, ?callable $resolve): array
    {
        $missing = [];
        foreach ($properties as $property) {
            if (!$this->present($node, explode('.', $property), $resolve, 0)) {
                $missing[] = $property;
            }
        }
        return $missing;
    }

    /**
     * @param string[] $path
     */
    protected function present(mixed $data, array $path, ?callable $resolve, int $depth): bool
    {
        if (!$path) {
            return !static::isEmpty($data);
        }
        if (!is_array($data) || $data === []) {
            # A plain value (location: "Utrecht") cannot have the nested property
            return false;
        }
        if (array_is_list($data)) {
            foreach ($data as $entry) {
                if (!$this->present($entry, $path, $resolve, $depth)) {
                    return false;
                }
            }
            return true;
        }
        if (count($data) === 1 && isset($data['@id']) && is_string($data['@id'])) {
            # A reference: follow it once per level, trust it when it cannot be followed
            $target = ($resolve && $depth < 5) ? $resolve($data['@id']) : null;
            if (!is_array($target)) {
                return true;
            }
            return $this->present($target, $path, $resolve, $depth + 1);
        }
        $key = array_shift($path);
        return array_key_exists($key, $data) && $this->present($data[$key], $path, $resolve, $depth);
    }

    protected static function isEmpty(mixed $value): bool
    {
        return $value === null || $value === '' || $value === [] || (is_string($value) && trim($value) === '');
    }

    /**
     * @param string|string[] $types
     * @return string[]
     */
    protected function collect(string|array $types, string $list): array
    {
        $all = [];
        foreach ((array) $types as $type) {
            $seen = [];
            # Walk up the parents; $seen stops a parent loop configured by mistake
            while (is_string($type) && $type !== '' && !isset($seen[$type])) {
                $seen[$type] = true;
                $definition = $this->types()[$type] ?? null;
                if (!is_array($definition)) {
                    break;
                }
                foreach ((array) ($definition[$list] ?? []) as $property) {
                    if (is_string($property) && $property !== '') {
                        $all[$property] = true;
                    }
                }
                $type = $definition['parent'] ?? null;
            }
        }
        return array_keys($all);
    }

    protected function types(): array
    {
        return (array) static::config()->get('types');
    }
}
