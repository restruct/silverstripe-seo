<?php

namespace Restruct\Silverstripe\SEO\Report;

use SilverStripe\Control\HTTPRequest;
use SilverStripe\Forms\GridField\GridFieldExportButton;
use SilverStripe\Forms\GridField\GridFieldSortableHeader;
use SilverStripe\ORM\FieldType\DBDatetime;

/**
 * The site report's CSV button: the listed rows (as filtered and sorted on screen, every page, not one page of
 * the list) with their own columns, written by {@see SeoReportCsv} so every cell is escaped against formula
 * injection.
 */
class SeoReportExportButton extends GridFieldExportButton
{
    public function handleExport($gridField, $request = null)
    {
        $fileName = sprintf('seo-report-%s.csv', date('Y-m-d-H-i', DBDatetime::now()->getTimestamp()));
        return HTTPRequest::send_file($this->generateExportFileData($gridField), $fileName, 'text/csv');
    }

    public function generateExportFileData($gridField)
    {
        $items = $gridField->getList();
        $sorter = $gridField->getConfig()->getComponentByType(GridFieldSortableHeader::class);
        if ($sorter) {
            $items = $sorter->getManipulatedData($gridField, $items);
        }
        $columns = SeoSiteReport::csvColumns();
        $rows = [];
        foreach ($items as $item) {
            $row = [];
            foreach (array_keys($columns) as $field) {
                $row[] = $item->$field;
            }
            $rows[] = $row;
        }
        return SeoReportCsv::toCsv(array_values($columns), $rows);
    }
}
