<?php

namespace App\Services\Upload;

use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;

/**
 * Builds the blank Amazon cancellations template (§G, §T).
 *
 * This is the ONLY file the team creates themselves - everything else is uploaded
 * exactly as its source tool produced it. Cancellations arrive by email, get pasted
 * into this sheet, and the sheet is uploaded.
 *
 * The columns are fixed and must not be renamed or reordered by hand. PO Number, ASIN
 * and External ID are written as TEXT cells so Excel cannot strip a leading zero or
 * turn an identifier into scientific notation (§B).
 */
class CancellationTemplate
{
    public const SHEET = 'Cancellations';

    public const COLUMNS = [
        'PO Number',
        'ASIN',
        'External ID',
        'Description',
        'Quantity Confirmed',
        'Quantity Cancelled',
    ];

    /** Columns that must stay text so identifiers survive intact. */
    private const TEXT_COLUMNS = ['A', 'B', 'C'];

    public function build(): Spreadsheet
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle(self::SHEET);

        foreach (self::COLUMNS as $index => $heading) {
            $column = chr(ord('A') + $index);

            $sheet->setCellValue($column.'1', $heading);
            $sheet->getColumnDimension($column)->setWidth($index === 3 ? 45 : 20);
        }

        $sheet->getStyle('A1:F1')->getFont()->setBold(true);
        $sheet->getStyle('A1:F1')->getFill()
            ->setFillType(Fill::FILL_SOLID)
            ->getStartColor()->setRGB('0D9488'); // Operon teal
        $sheet->getStyle('A1:F1')->getFont()->getColor()->setRGB('FFFFFF');
        $sheet->freezePane('A2');

        // Force the identifier columns to Text for a generous number of rows, so a
        // paste from an email cannot silently mangle an ASIN or a barcode.
        foreach (self::TEXT_COLUMNS as $column) {
            $sheet->getStyle($column.'2:'.$column.'2000')
                ->getNumberFormat()
                ->setFormatCode('@');
        }

        // One example row, clearly marked, so the expected shape is obvious.
        $example = ['774FV9FB', 'B0XXXXXXXX', '0634562947130', 'Example row - delete before uploading', 120, 20];

        foreach ($example as $index => $value) {
            $column = chr(ord('A') + $index);
            $coordinate = $column.'2';

            in_array($column, self::TEXT_COLUMNS, true)
                ? $sheet->setCellValueExplicit($coordinate, (string) $value, DataType::TYPE_STRING)
                : $sheet->setCellValue($coordinate, $value);
        }

        $sheet->getStyle('A2:F2')->getFont()->setItalic(true)->getColor()->setRGB('9CA3AF');

        return $spreadsheet;
    }

    /** Write the template to a path and return it. */
    public function writeTo(string $path): string
    {
        $spreadsheet = $this->build();
        (new XlsxWriter($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        return $path;
    }

    public static function filename(): string
    {
        return 'Amazon_Cancellations_TEMPLATE.xlsx';
    }
}
