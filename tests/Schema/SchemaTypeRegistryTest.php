<?php

namespace Restruct\Silverstripe\SEO\Tests\Schema;

use Restruct\Silverstripe\SEO\Schema\SchemaTypeRegistry;
use SilverStripe\Core\Config\Config;
use SilverStripe\Dev\SapphireTest;

/**
 * Required and recommended properties per schema type, used to keep incomplete nodes out of the page and to
 * tell editors (and the later checks) what is missing.
 */
class SchemaTypeRegistryTest extends SapphireTest
{
    private function registry(): SchemaTypeRegistry
    {
        return SchemaTypeRegistry::create();
    }

    public function testShippedTypes(): void
    {
        $registry = $this->registry();
        foreach ([
            'Organization', 'LocalBusiness', 'WebSite', 'WebPage', 'BreadcrumbList', 'Event', 'EducationEvent',
            'JobPosting', 'Course', 'CourseInstance', 'EducationalOccupationalProgram', 'Article', 'NewsArticle',
            'Place', 'PostalAddress', 'ImageObject',
        ] as $type) {
            $this->assertTrue($registry->isKnown($type), "$type should be known");
        }
        # Rich result types Google retired are not offered
        $this->assertFalse($registry->isKnown('FAQPage'));
        $this->assertFalse($registry->isKnown('HowTo'));
    }

    public function testRequiredPropertiesFollowGoogle(): void
    {
        $registry = $this->registry();
        $this->assertEqualsCanonicalizing(
            ['datePosted', 'description', 'hiringOrganization', 'jobLocation', 'title'],
            $registry->required('JobPosting')
        );
        $this->assertEqualsCanonicalizing(['name', 'startDate', 'location', 'location.address'], $registry->required('Event'));
        $this->assertEqualsCanonicalizing(['name', 'url'], $registry->required('WebSite'));
    }

    public function testSubtypesInheritFromTheirParent(): void
    {
        $registry = $this->registry();
        $this->assertEqualsCanonicalizing($registry->required('Event'), $registry->required('EducationEvent'));
        $this->assertContains('name', $registry->required('LocalBusiness'));
        $this->assertContains('address', $registry->required('LocalBusiness'));
        $this->assertContains('headline', $registry->required('NewsArticle'));
    }

    public function testOrganizationSubtypesKeepTheNameRequired(): void
    {
        $registry = $this->registry();
        foreach (['EducationalOrganization', 'CollegeOrUniversity', 'Corporation', 'NGO'] as $type) {
            $this->assertSame(['name'], $registry->missingRequired(['@type' => $type]), $type);
        }
    }

    public function testMissingRequired(): void
    {
        $registry = $this->registry();
        $event = [
            '@type' => 'Event',
            'name' => 'Open day',
            'startDate' => '2027-03-01',
            'location' => ['@type' => 'Place', 'name' => 'Main building'],
        ];
        $this->assertSame(['location.address'], $registry->missingRequired($event));

        $event['location']['address'] = ['@type' => 'PostalAddress', 'addressLocality' => 'Utrecht'];
        $this->assertSame([], $registry->missingRequired($event));

        # Empty values count as missing
        $event['name'] = '';
        $this->assertSame(['name'], $registry->missingRequired($event));
    }

    public function testEveryEntryOfAListMustHaveANestedProperty(): void
    {
        $registry = $this->registry();
        $event = [
            '@type' => 'Event',
            'name' => 'Open day',
            'startDate' => '2027-03-01',
            'location' => [
                ['@type' => 'Place', 'address' => 'Somewhere 1'],
                ['@type' => 'Place', 'name' => 'No address'],
            ],
        ];
        $this->assertSame(['location.address'], $registry->missingRequired($event));
    }

    public function testReferencesAreResolvedWhenAResolverIsGiven(): void
    {
        $registry = $this->registry();
        $event = [
            '@type' => 'Event',
            'name' => 'Open day',
            'startDate' => '2027-03-01',
            'location' => ['@id' => 'https://example.com/#place-1'],
        ];
        # Without a resolver a reference is taken on trust
        $this->assertSame([], $registry->missingRequired($event));

        $nodes = ['https://example.com/#place-1' => ['@type' => 'Place', 'name' => 'No address']];
        $resolve = fn (string $id) => $nodes[$id] ?? null;
        $this->assertSame(['location.address'], $registry->missingRequired($event, $resolve));
    }

    public function testSeveralTypesCombine(): void
    {
        $registry = $this->registry();
        $required = $registry->required(['Event', 'Course']);
        $this->assertContains('startDate', $required);
        $this->assertContains('description', $required);
    }

    public function testUnknownTypeRequiresNothing(): void
    {
        $this->assertSame([], $this->registry()->missingRequired(['@type' => 'SomethingElse']));
        $this->assertSame([], $this->registry()->missingRequired(['name' => 'No type at all']));
    }

    public function testMissingRecommended(): void
    {
        $missing = $this->registry()->missingRecommended([
            '@type' => 'JobPosting',
            'employmentType' => 'FULL_TIME',
        ]);
        $this->assertContains('validThrough', $missing);
        $this->assertContains('baseSalary', $missing);
        $this->assertNotContains('employmentType', $missing);
    }

    public function testProjectsAddTypesInYaml(): void
    {
        Config::modify()->merge(SchemaTypeRegistry::class, 'types', [
            'Occupation' => ['required' => ['name'], 'recommended' => ['occupationalCategory']],
            'Event' => ['recommended' => ['typicalAgeRange']],
        ]);
        $registry = $this->registry();
        $this->assertTrue($registry->isKnown('Occupation'));
        $this->assertSame(['name'], $registry->missingRequired(['@type' => 'Occupation']));
        $this->assertContains('typicalAgeRange', $registry->recommended('Event'));
    }
}
