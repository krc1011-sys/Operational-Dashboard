<?php

namespace App\Services\Upload;

use App\Enums\UploadType;
use App\Services\Spreadsheet\HeaderMap;
use App\Services\Spreadsheet\Sheet;
use App\Services\Spreadsheet\Workbook;
use Throwable;

/**
 * The §J validation guardrail.
 *
 * "If the uploaded file doesn't match the chosen type, reject it with a clear message
 * before importing anything." This is the whole job of this class. It never imports and
 * never guesses the type - the user already chose it.
 *
 * The header row is FOUND rather than assumed: the definition's row number is only a
 * starting hint, and the scan picks whichever row in the first few matches the most
 * expected headers. That is what stops a one-row shift in an Amazon export from
 * breaking every upload.
 */
class UploadValidator
{
    public function validate(string $path, UploadType $type, ?string $originalFilename = null): ValidationResult
    {
        $definition = FileTypeRegistry::for($type);

        // 1. Extension - checked against the original filename, since a stored upload
        //    typically has a random name.
        $extension = strtolower(pathinfo($originalFilename ?? $path, PATHINFO_EXTENSION));

        if ($extension !== '' && ! in_array($extension, $definition->extensions, true)) {
            return ValidationResult::fail(sprintf(
                'You chose "%s", which expects a %s file, but this file is a .%s. '
                .'Upload the file exactly as it was sent to you, without converting it.',
                $type->label(),
                $definition->extensionsLabel(),
                $extension
            ));
        }

        // 2. Does it open at all?
        try {
            $workbook = Workbook::open($path);
        } catch (Throwable $e) {
            return ValidationResult::fail($e->getMessage());
        }

        try {
            return $this->inspect($workbook, $definition, $type);
        } finally {
            $workbook->close();
        }
    }

    private function inspect(Workbook $workbook, FileTypeDefinition $definition, UploadType $type): ValidationResult
    {
        // 3. The right tab.
        $sheetName = null;

        if ($definition->sheetCandidates !== []) {
            $sheetName = $workbook->findSheetName(...$definition->sheetCandidates);

            if ($sheetName === null) {
                return ValidationResult::fail(sprintf(
                    'You chose "%s", which reads the %s tab, but this file has no such tab. '
                    .'It contains: %s. Either the wrong file type was selected, or this is '
                    .'not the file you meant to upload.',
                    $type->label(),
                    $definition->sheetLabel(),
                    implode(', ', array_map(fn ($n) => "\"{$n}\"", $workbook->sheetNames()))
                ));
            }
        }

        $sheet = $workbook->sheet($sheetName);
        $sheetName ??= $sheet->title();

        // 4. Find the header row and check the required columns are there.
        [$headerRow, $headers] = $this->locateHeaderRow($sheet, $definition);

        if ($headers === null) {
            return ValidationResult::fail(sprintf(
                'The %s tab of this file has no recognisable header row in its first %d rows. '
                .'A "%s" file should have a header containing: %s.',
                $sheetName,
                $definition->headerSearchDepth,
                $type->label(),
                implode(', ', array_keys($definition->requiredHeaders))
            ));
        }

        $missing = $headers->missing($definition->requiredHeaders);

        if ($missing !== []) {
            return ValidationResult::fail(sprintf(
                'You chose "%s", but this file is missing the required column%s %s. '
                .'The columns found on the %s tab were: %s.',
                $type->label(),
                count($missing) === 1 ? '' : 's',
                implode(', ', array_map(fn ($m) => "\"{$m}\"", $missing)),
                $sheetName,
                implode(', ', array_slice($headers->headers(), 0, 25)) ?: '(none)'
            ));
        }

        // 5. Passed. Anything else is a warning, not a rejection.
        $warnings = [];

        foreach ($definition->optionalHeaders as $label => $aliases) {
            if (! $headers->has(...$aliases)) {
                $warnings[] = "Optional column \"{$label}\" was not found - "
                    .'anything derived from it will be blank.';
            }
        }

        if ($sheet->highestRow() <= $headerRow) {
            return ValidationResult::fail(sprintf(
                'The %s tab has a valid header but no data rows underneath it.',
                $sheetName
            ));
        }

        return ValidationResult::pass($sheetName, $headerRow, $headers, $warnings);
    }

    /**
     * Score each candidate row by how many expected headers it contains, and take the
     * best. The hint row wins ties, so a normal file still resolves instantly.
     *
     * @return array{0: int, 1: ?HeaderMap}
     */
    private function locateHeaderRow(Sheet $sheet, FileTypeDefinition $definition): array
    {
        $expected = $definition->allHeaders();
        $required = $definition->requiredHeaders;
        $depth = min($definition->headerSearchDepth, max(1, $sheet->highestRow()));

        $bestRow = null;
        $bestMap = null;
        $bestScore = 0;

        for ($row = 1; $row <= $depth; $row++) {
            $map = HeaderMap::fromRow($sheet->row($row));

            if ($map->isEmpty()) {
                continue;
            }

            $score = $map->matchCount($expected);

            // Only a row carrying every REQUIRED header can win outright; among those,
            // the richest match wins, with the hint row breaking ties.
            $complete = $map->missing($required) === [];
            $score += $complete ? 1000 : 0;
            $score += ($row === $definition->headerRowHint) ? 1 : 0;

            if ($score > $bestScore) {
                $bestScore = $score;
                $bestRow = $row;
                $bestMap = $map;
            }
        }

        return [$bestRow ?? $definition->headerRowHint, $bestMap];
    }
}
