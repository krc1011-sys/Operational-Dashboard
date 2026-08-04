<?php

namespace App\Services\Upload;

use App\Models\SourceFile;

/**
 * Contract for the per-file-type parsers that arrive at M3.
 *
 * The upload pipeline (store -> validate -> import) is complete without them; a
 * validated file with no registered importer simply rests at "validated", which is
 * why an upload today tells you truthfully that the file is the right one and that
 * nothing has been written yet.
 */
interface Importer
{
    /**
     * Parse the file and write its rows. Receives the SourceFile audit record and the
     * validation result, which already carries the resolved sheet name, header row and
     * header map - so an importer never re-derives them.
     */
    public function import(SourceFile $sourceFile, string $path, ValidationResult $validation): ImportResult;
}
