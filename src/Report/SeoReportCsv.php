<?php

namespace Restruct\Silverstripe\SEO\Report;

/**
 * Writes the site report as CSV, with every cell made safe to open in a spreadsheet.
 *
 * Formula injection: a cell that starts with = + - @ (or a tab or carriage return, which some spreadsheets skip
 * before reading a formula) is run as a formula when the file is opened. Page titles are typed by editors, so
 * such a cell gets a leading single quote, which spreadsheets read as "text" and do not show. Core's export
 * button prefixes a tab instead; this export does not use it, so a cell is escaped once.
 */
class SeoReportCsv
{
    /**
     * @param string[] $header
     * @param array<int, array<int, mixed>> $rows
     */
    public static function toCsv(array $header, array $rows): string
    {
        $handle = fopen('php://temp', 'r+');
        # A BOM, so spreadsheets read the file as UTF-8 (accented titles)
        fwrite($handle, "\xEF\xBB\xBF");
        foreach (array_merge([$header], $rows) as $row) {
            fputcsv($handle, array_map([static::class, 'escapeCell'], array_values($row)), ',', '"', '', "\r\n");
        }
        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);
        return $csv;
    }

    public static function escapeCell(mixed $value): string
    {
        $value = is_bool($value) ? ($value ? '1' : '0') : (string) $value;
        return preg_match('/^[=+\-@\t\r]/', $value) ? "'" . $value : $value;
    }
}
