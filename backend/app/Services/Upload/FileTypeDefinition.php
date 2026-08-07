<?php

namespace App\Services\Upload;

use App\Enums\UploadType;

/**
 * The fingerprint of one upload type (blueprint §J, §T).
 *
 * The user picks the type from a dropdown FIRST; this describes what a file of that
 * type must look like, so a mismatch is rejected with a clear message before a single
 * row is imported. No auto-guessing, no "detect then confirm".
 */
class FileTypeDefinition
{
    /**
     * @param  string[]  $extensions  allowed file extensions
     * @param  string[]  $sheetCandidates  acceptable sheet names; empty = use the first sheet
     * @param  int  $headerRowHint  where the header usually is
     * @param  array<string, string[]>  $requiredHeaders  label => aliases, all must be present
     * @param  array<string, string[]>  $optionalHeaders  label => aliases, nice to have
     */
    public function __construct(
        public readonly UploadType $type,
        public readonly array $extensions,
        public readonly array $sheetCandidates,
        public readonly int $headerRowHint,
        public readonly array $requiredHeaders,
        public readonly array $optionalHeaders = [],
        public readonly ?string $expectedFilename = null,
        public readonly string $notes = '',
        /**
         * How far down to hunt for the header row. The hint is only a hint: Amazon and
         * the packing tool both shift rows occasionally, so the validator scans and
         * picks whichever row matches the most expected headers.
         */
        public readonly int $headerSearchDepth = 12,
        /**
         * May this file legitimately carry a header and NO data rows?
         *
         * False for almost everything: an empty Amazon packing list means somebody
         * uploaded the wrong thing, and rejecting it is a kindness. It is true for
         * Noon's picking lists, where an empty tab is not an empty file but a
         * MEANINGFUL one - Noon annotates only exceptions, so "no rows" says "every
         * line went out in full" (§Q). Rejecting that would refuse the happiest file
         * the system ever receives.
         */
        public readonly bool $allowsNoDataRows = false,
    ) {}

    /** Every header we know about, for scoring a candidate header row. */
    public function allHeaders(): array
    {
        return $this->requiredHeaders + $this->optionalHeaders;
    }

    public function extensionsLabel(): string
    {
        return implode(' or ', array_map(fn ($e) => '.'.$e, $this->extensions));
    }

    public function sheetLabel(): string
    {
        return $this->sheetCandidates === []
            ? 'the first sheet'
            : '"'.implode('" or "', $this->sheetCandidates).'"';
    }
}
