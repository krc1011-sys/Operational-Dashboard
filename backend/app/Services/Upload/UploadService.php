<?php

namespace App\Services\Upload;

use App\Enums\SourceFileStatus;
use App\Enums\UploadType;
use App\Models\SourceFile;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * The §J upload pipeline: store -> validate -> import, with every step audited.
 *
 * The order matters. The audit record is created BEFORE validation, so a rejected file
 * still leaves a trace of who tried to upload what and why it bounced. Nothing is
 * written to the data tables until validation passes.
 */
class UploadService
{
    public function __construct(
        private readonly UploadValidator $validator,
        private readonly ImporterRegistry $importers,
    ) {}

    /**
     * @param  array<string, mixed>  $context  extra facts the file itself cannot supply,
     *                                         e.g. the PO number for a single-PO export
     *                                         whose filename does not carry one (§C).
     */
    public function handle(UploadedFile $file, UploadType $type, User $user, array $context = []): SourceFile
    {
        $sourceFile = $this->store($file, $type, $user, $context);

        [$path, $temporary] = $this->readableCopy($sourceFile->stored_path);

        try {
            return $this->validateAndImport($sourceFile, $path, $type, $file->getClientOriginalName());
        } finally {
            if ($temporary && is_file($path)) {
                @unlink($path);
            }
        }
    }

    private function validateAndImport(SourceFile $sourceFile, string $path, UploadType $type, string $originalName): SourceFile
    {
        $validation = $this->validator->validate($path, $type, $originalName);

        if (! $validation->passed) {
            $sourceFile->update([
                'status' => SourceFileStatus::Rejected,
                'rejection_reason' => $validation->message(),
            ]);

            return $sourceFile;
        }

        $sourceFile->update([
            'status' => SourceFileStatus::Validated,
            'summary' => array_merge($sourceFile->summary ?? [], [
                'sheet' => $validation->sheetName,
                'header_row' => $validation->headerRow,
                'headers_found' => $validation->headers?->headers(),
            ]),
            'warnings' => $validation->warnings,
        ]);

        return $this->import($sourceFile, $path, $validation);
    }

    /**
     * Save the file and open its audit record. The content hash lets us spot the same
     * file being uploaded twice - common when someone re-forwards an email attachment.
     */
    private function store(UploadedFile $file, UploadType $type, User $user, array $context = []): SourceFile
    {
        $path = $file->store('uploads/'.$type->value, self::disk());

        return SourceFile::create([
            'summary' => array_filter($context, fn ($v) => $v !== null && $v !== ''),
            'upload_type' => $type,
            'marketplace' => $type->marketplace(),
            'channel' => $type->channel(),
            'original_filename' => $file->getClientOriginalName(),
            'stored_path' => $path,
            'size_bytes' => $file->getSize(),
            'content_hash' => hash_file('sha256', $file->getRealPath()),
            'status' => SourceFileStatus::Pending,
            'uploaded_by' => $user->id,
        ]);
    }

    /** Run the type's parser, if one is registered yet. */
    private function import(SourceFile $sourceFile, string $path, ValidationResult $validation): SourceFile
    {
        $importer = $this->importers->for($sourceFile->upload_type);

        if ($importer === null) {
            // No parser yet (M3). The file is verified and stored; nothing was written.
            return $sourceFile;
        }

        $sourceFile->update(['status' => SourceFileStatus::Importing]);

        try {
            $result = $importer->import($sourceFile, $path, $validation);
        } catch (Throwable $e) {
            $sourceFile->update([
                'status' => SourceFileStatus::Failed,
                'rejection_reason' => self::readableReason($e),
            ]);

            report($e);

            return $sourceFile;
        }

        $sourceFile->update([
            'status' => SourceFileStatus::Imported,
            'rows_read' => $result->rowsRead,
            'rows_imported' => $result->rowsImported,
            'rows_skipped' => $result->rowsSkipped,
            'rows_unmatched' => $result->rowsUnmatched,
            'summary' => array_merge($sourceFile->summary ?? [], $result->summary),
            // Validation warnings are already stored; add the importer's own without
            // repeating anything.
            'warnings' => array_values(array_unique(
                array_merge($sourceFile->warnings ?? [], $result->warnings)
            )),
            'imported_at' => now(),
        ]);

        return $sourceFile->fresh();
    }

    /**
     * Why the import failed, in a form that fits in the audit row.
     *
     * A QueryException carries the whole failing statement in its message, bound
     * values and all - which for a bulk insert of a thousand product titles is
     * megabytes of SQL. Storing that verbatim overflowed the column and threw a
     * SECOND exception from inside the failure handler, so the file was left stuck on
     * "importing" and the user got a raw 500 instead of being told what went wrong.
     *
     * The full exception still goes to the log via report(); this is the sentence a
     * person reads on the upload screen.
     */
    private static function readableReason(Throwable $e): string
    {
        $message = $e->getMessage();

        // Cut a database error off at the start of the statement it was complaining
        // about - everything before "(Connection:" is the part that says what is wrong.
        if (($sqlAt = strpos($message, ' (Connection:')) !== false) {
            $message = substr($message, 0, $sqlAt);
        }

        return Str::limit(trim($message), 2000);
    }

    /**
     * The disk raw uploads are written to - `local` unless an object-storage bucket
     * has been configured. See config/operon.php `uploads_disk`.
     */
    public static function disk(): string
    {
        return (string) config('operon.uploads_disk', 'local');
    }

    /**
     * A path on the real filesystem that PhpSpreadsheet can open.
     *
     * The parsers read workbooks with fopen, so a stored file has to become a genuine
     * local path before it reaches them. On a local disk that is the file itself; on
     * object storage it is a temp copy, and the caller deletes it afterwards.
     *
     * @return array{0: string, 1: bool} [path, caller must delete it]
     */
    private function readableCopy(string $storedPath): array
    {
        $disk = Storage::disk(self::disk());

        if (config('filesystems.disks.'.self::disk().'.driver') === 'local') {
            return [$disk->path($storedPath), false];
        }

        $temp = tempnam(sys_get_temp_dir(), 'operon_upload_');

        file_put_contents($temp, $disk->get($storedPath));

        return [$temp, true];
    }

    /** Has this exact file been uploaded before? (§J - warn, do not block.) */
    public function previousUploadOfSameFile(string $hash, ?int $exceptId = null): ?SourceFile
    {
        return SourceFile::query()
            ->where('content_hash', $hash)
            ->when($exceptId, fn ($q) => $q->whereKeyNot($exceptId))
            ->latest('id')
            ->first();
    }
}
