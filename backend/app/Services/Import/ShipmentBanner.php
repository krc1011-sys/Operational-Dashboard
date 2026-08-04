<?php

namespace App\Services\Import;

use App\Services\Spreadsheet\CellValue;
use App\Services\Spreadsheet\Sheet;
use Illuminate\Support\Carbon;

/**
 * Reads the "Shipment Name" / "Shipment Date" banner off a packing list (§K).
 *
 * The banner sits in D1/D2 on an interim sheet and F1/F2 on a final, so it is found by
 * its LABEL rather than a coordinate. Verified against the real files.
 *
 * The shipment name holds an 11-digit ASN plus an internal reference, in either order:
 *
 *     "Shipment Name: Aug-01-22161389743"     -> ASN 22161389743, ref "Aug-01"
 *     "Shipment Name: 22161964743-Aug-02"     -> ASN 22161964743, ref "Aug-02"
 *     "Shipment Name: 22183953643-AUG-25"     -> ASN 22183953643, ref "AUG-25"
 *
 * The ASN is the delivery key. "Aug-0X" is an internal label, NOT a date.
 */
class ShipmentBanner
{
    private function __construct(
        public readonly ?string $asn,
        public readonly ?string $internalRef,
        public readonly ?Carbon $plannedDate,
        public readonly ?string $rawName,
        public readonly ?string $rawDate,
        public readonly ?float $invoiceValue,
    ) {}

    public static function readFrom(Sheet $sheet): self
    {
        $rawName = $sheet->findTextContaining('Shipment Name');
        $rawDate = $sheet->findTextContaining('Shipment Date');

        $name = self::stripLabel($rawName, 'Shipment Name');

        return new self(
            asn: self::extractAsn($name),
            internalRef: self::extractRef($name),
            plannedDate: CellValue::asDate(self::stripLabel($rawDate, 'Shipment Date')),
            rawName: $rawName,
            rawDate: $rawDate,
            invoiceValue: self::findInvoiceValue($sheet),
        );
    }

    private static function stripLabel(?string $text, string $label): ?string
    {
        if ($text === null) {
            return null;
        }

        $text = preg_replace('/^\s*'.preg_quote($label, '/').'\s*:?\s*/i', '', $text) ?? $text;

        return trim($text) === '' ? null : trim($text);
    }

    /** The 11-digit ASN, wherever it sits in the name. */
    private static function extractAsn(?string $name): ?string
    {
        if ($name === null) {
            return null;
        }

        // Allow 10-12 digits so a format change does not silently drop the delivery.
        return preg_match('/\b(\d{10,12})\b/', $name, $m) === 1 ? $m[1] : null;
    }

    /** Whatever is left once the ASN is removed, e.g. "Aug-01". */
    private static function extractRef(?string $name): ?string
    {
        if ($name === null) {
            return null;
        }

        $ref = preg_replace('/\b\d{10,12}\b/', '', $name) ?? $name;
        $ref = trim($ref, " \t-_/");

        return $ref === '' ? null : $ref;
    }

    /**
     * The "Invoice value" total: labelled in G1 on interim sheets and I1 on finals,
     * with the figure in the cell beneath. Found by label, like the banner.
     */
    private static function findInvoiceValue(Sheet $sheet): ?float
    {
        for ($col = 1; $col <= 14; $col++) {
            $label = CellValue::asText($sheet->cellAt(1, $col));

            if ($label !== null && stripos($label, 'invoice value') !== false) {
                return CellValue::asDecimal($sheet->cellAt(2, $col));
            }
        }

        return null;
    }

    /** A delivery must at least be identifiable. */
    public function isUsable(): bool
    {
        return $this->asn !== null;
    }
}
