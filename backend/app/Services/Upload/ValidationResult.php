<?php

namespace App\Services\Upload;

use App\Services\Spreadsheet\HeaderMap;

/**
 * The outcome of checking an uploaded file against its chosen type's fingerprint (§J).
 *
 * On failure the messages are written for the person uploading, not for a developer:
 * they say which type was chosen, what was expected, and what the file actually
 * contained, so the fix is obvious without opening a log.
 */
class ValidationResult
{
    /**
     * @param  string[]  $errors    fatal - nothing is imported
     * @param  string[]  $warnings  imported, but worth knowing
     */
    private function __construct(
        public readonly bool $passed,
        public readonly array $errors = [],
        public readonly array $warnings = [],
        public readonly ?string $sheetName = null,
        public readonly ?int $headerRow = null,
        public readonly ?HeaderMap $headers = null,
    ) {}

    public static function pass(
        string $sheetName,
        int $headerRow,
        HeaderMap $headers,
        array $warnings = []
    ): self {
        return new self(true, [], $warnings, $sheetName, $headerRow, $headers);
    }

    public static function fail(string|array $errors): self
    {
        return new self(false, (array) $errors);
    }

    public function message(): string
    {
        return implode(' ', $this->errors);
    }
}
