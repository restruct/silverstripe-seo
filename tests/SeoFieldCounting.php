<?php

namespace Restruct\Silverstripe\SEO\Tests;

use SilverStripe\Forms\CompositeField;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\FormField;

/**
 * Counts data fields by walking the field tree itself. FieldList::getDataFields() returns one entry per field
 * name, so a field that is present twice would be counted once; this walk counts every occurrence.
 */
trait SeoFieldCounting
{
    /**
     * @return array<string,int> Occurrences of each data field, by name
     */
    protected function countFields(FieldList $fields): array
    {
        $counts = [];
        $walk = function (FieldList $list) use (&$walk, &$counts): void {
            foreach ($list as $field) {
                /** @var FormField $field */
                if ($field instanceof CompositeField) {
                    # Tabs, toggles and groups: count what they hold, not the container
                    $walk($field->getChildren());
                    continue;
                }
                if ($field->hasData() && $field->getName()) {
                    $counts[$field->getName()] = ($counts[$field->getName()] ?? 0) + 1;
                }
            }
        };
        $walk($fields);
        return $counts;
    }
}
