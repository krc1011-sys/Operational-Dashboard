<?php

namespace App\Services\Import;

use App\Services\Spreadsheet\CellValue;
use App\Services\Spreadsheet\Sheet;
use Illuminate\Support\Carbon;

/**
 * Row 1 of an Amazon Vendor Central report — the metadata banner (§P, M9).
 *
 * Both M9 Amazon files come out of the same export tool and both put a banner above the
 * header, one fact per cell:
 *
 *     Program=[Retail]  Distributor View=[Sourcing]  View By=[ASIN]  Countries=[AE]
 *     Businesses=[Brands Dynamo LLC_AE]  Locale=[en_AE]  Currency=[AED]
 *     Reporting Range=[Custom]  Viewing Range=[01/06/2026 - 05/08/2026]
 *     Report Updated=[05/08/2026]
 *
 * ═══ WHY THIS IS NOT COSMETIC ═══
 *
 * The Viewing Range is the DENOMINATOR of Amazon's run rate. The sell-out report has no
 * daily detail at all — it is one aggregated row per ASIN — so units per day can only be
 * "shipped units ÷ the number of days this file covers", and getting that number from
 * anywhere other than the file is guessing at the very input the answer is most
 * sensitive to. Days of cover then divides by that rate, so a wrong window is a wrong
 * reorder decision.
 *
 * ═══ THE DATE FORMAT TRAP ═══
 *
 * These dates are dd/mm/yyyy — the locale is en_AE. `Carbon::parse('05/08/2026')` reads
 * it as 5 AUGUST or 8 MAY depending on nothing you can see, and on this real file it
 * would silently move the window end back three months and treble every run rate. So the
 * format is stated explicitly, and a value that does not fit it is refused rather than
 * reinterpreted.
 *
 * Nothing is read by cell coordinate: each fact is found by its own label, because the
 * banner's cells shift when Amazon adds a field (the inventory file's banner already
 * spills into a second line where the sell-out's does not).
 */
class AmazonReportBanner
{
    /** How Amazon writes a date in this banner. en_AE, so day first. */
    private const DATE_FORMAT = 'd/m/Y';

    private function __construct(
        public readonly ?Carbon $periodStart,
        public readonly ?Carbon $periodEnd,
        public readonly ?Carbon $reportUpdated,
        public readonly ?string $currency,
        public readonly ?string $viewBy,
        public readonly ?string $program,
        /** @var array<string, string> every label=[value] pair found, verbatim */
        public readonly array $fields,
    ) {}

    /** Read the banner from the top of a sheet. Scans a few rows, as the banner wraps. */
    public static function readFrom(Sheet $sheet, int $maxRow = 1): self
    {
        $fields = [];

        for ($row = 1; $row <= $maxRow; $row++) {
            foreach ($sheet->row($row) as $value) {
                $text = CellValue::asText($value);

                if ($text === null) {
                    continue;
                }

                // "Viewing Range=[01/06/2026 - 05/08/2026]" — and the value may itself
                // contain brackets, so the match is anchored on the LAST closing one.
                if (preg_match('/^(.*?)=\[(.*)\]$/s', trim($text), $m)) {
                    $label = self::normalise($m[1]);

                    if ($label !== '' && ! isset($fields[$label])) {
                        $fields[$label] = trim($m[2]);
                    }
                }
            }
        }

        [$start, $end] = self::parseRange($fields['viewing range'] ?? null);

        return new self(
            periodStart: $start,
            periodEnd: $end,
            reportUpdated: self::parseDate($fields['report updated'] ?? null),
            currency: $fields['currency'] ?? null,
            viewBy: $fields['view by'] ?? null,
            program: $fields['program'] ?? null,
            fields: $fields,
        );
    }

    /** Did we actually find a window? Callers must not invent one when we did not. */
    public function hasPeriod(): bool
    {
        return $this->periodStart !== null && $this->periodEnd !== null;
    }

    /**
     * How many days the report covers, inclusive of both ends.
     *
     * Null when the banner did not state a window. Deliberately not defaulted: a made-up
     * denominator produces a confident run rate that is simply wrong, and the importer
     * would rather store the units with no rate than store a rate nobody can check.
     */
    public function days(): ?int
    {
        if (! $this->hasPeriod()) {
            return null;
        }

        return max(1, (int) $this->periodStart->diffInDays($this->periodEnd) + 1);
    }

    public function label(): string
    {
        return $this->hasPeriod()
            ? $this->periodStart->format('d M Y').' – '.$this->periodEnd->format('d M Y')
            : 'window not stated in the file';
    }

    /** @return array{0: ?Carbon, 1: ?Carbon} */
    private static function parseRange(?string $range): array
    {
        if ($range === null) {
            return [null, null];
        }

        // "01/06/2026 - 05/08/2026", with an ASCII hyphen or an en dash.
        $parts = preg_split('/\s*[-–—]\s*/u', trim($range)) ?: [];

        if (count($parts) < 2) {
            $single = self::parseDate($range);

            return [$single, $single];
        }

        return [self::parseDate($parts[0]), self::parseDate($parts[1])];
    }

    /**
     * A banner date, in the format the banner uses and no other.
     *
     * `createFromFormat` is used rather than `parse` precisely because it FAILS on a
     * value that is not dd/mm/yyyy instead of quietly picking a different reading.
     */
    private static function parseDate(?string $value): ?Carbon
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        $date = Carbon::createFromFormat(self::DATE_FORMAT, $value);

        // createFromFormat is forgiving about a bad day/month pair; comparing the
        // round-trip catches "31/02/2026" and anything else that was coerced.
        if ($date === false || $date->format(self::DATE_FORMAT) !== $value) {
            return null;
        }

        return $date->startOfDay();
    }

    private static function normalise(string $label): string
    {
        $label = strtolower(trim($label));

        return trim(preg_replace('/\s+/', ' ', $label) ?? $label);
    }
}
