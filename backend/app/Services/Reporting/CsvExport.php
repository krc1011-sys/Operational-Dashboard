<?php

namespace App\Services\Reporting;

use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * "Export" on every screen (§M).
 *
 * Streamed rather than built in memory, because a filtered fulfilment export can be the
 * whole PO book. The file opens straight in Excel:
 *
 *  - a UTF-8 BOM, so Arabic product titles are not mangled;
 *  - identifiers written with a leading apostrophe, so Excel cannot turn an ASIN or a
 *    barcode into a number and lose its leading zero (§B) - the same trap the
 *    cancellations template avoids on the way in.
 */
class CsvExport
{
    /**
     * @param  array<int, string>  $headers
     * @param  iterable<int, array<int, mixed>>  $rows
     * @param  array<int, int>  $identifierColumns  zero-based columns to protect from Excel
     * @param  array<int, string>  $notes  written above the table - the filters that were on
     */
    public static function stream(
        string $filename,
        array $headers,
        iterable $rows,
        array $identifierColumns = [],
        array $notes = [],
    ): StreamedResponse {
        return response()->streamDownload(function () use ($headers, $rows, $identifierColumns, $notes) {
            $out = fopen('php://output', 'w');

            fwrite($out, "\xEF\xBB\xBF"); // BOM

            foreach ($notes as $note) {
                fputcsv($out, [$note], escape: '\\');
            }

            if ($notes !== []) {
                fputcsv($out, [], escape: '\\');
            }

            fputcsv($out, $headers, escape: '\\');

            foreach ($rows as $row) {
                foreach ($identifierColumns as $column) {
                    if (isset($row[$column]) && $row[$column] !== '') {
                        $row[$column] = "'".$row[$column];
                    }
                }

                fputcsv($out, $row, escape: '\\');
            }

            fclose($out);
        }, self::filename($filename), [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /** e.g. "operon-fulfilment-2026-08-04.csv" */
    public static function filename(string $screen): string
    {
        return 'operon-'.Str::slug($screen).'-'.now()->toDateString().'.csv';
    }
}
