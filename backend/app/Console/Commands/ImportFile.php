<?php

namespace App\Console\Commands;

use App\Enums\SourceFileStatus;
use App\Enums\UploadType;
use App\Models\SourceFile;
use App\Models\User;
use App\Services\Upload\UploadService;
use Illuminate\Console\Command;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;

/**
 * Load a data file from the command line, through the same pipeline as the UI.
 *
 * On a deployed environment there is no obvious way to get a 3 MB workbook into the
 * app except by signing in and using the Uploads screen. That works, and it stays the
 * documented route - but it is a manual step, and re-populating a staging environment
 * after a database reset is exactly the sort of thing that should be one command.
 *
 * This is that command. It is deliberately NOT a second import path: it builds an
 * UploadedFile and hands it to the same UploadService the controller uses, so the file
 * gets the same fingerprint validation, the same parser, the same audit row and the
 * same duplicate detection. A file that imports here imports identically in the UI.
 *
 * The source may be a local path or an https URL, because the real workbooks contain
 * genuine costs and are deliberately kept out of git (see .gitignore) - so on a cloud
 * environment there is no local file to point at, and a temporary signed link is.
 *
 *   php artisan operon:import master_sheet ./OperON_Master_Merged.xlsx
 *   php artisan operon:import amazon_po_bulk "https://.../POItemExport.xls"
 *   php artisan operon:import amazon_po_single ./PurchaseOrder.xlsx --po=22161964743
 */
class ImportFile extends Command
{
    protected $signature = 'operon:import
                            {type : The upload type, e.g. master_sheet. Omit the value to list them}
                            {source* : One or more local paths or https URLs}
                            {--as= : Email of the user to record as the uploader (default: the first Admin)}
                            {--po= : PO number, for a single-PO export that does not carry one}
                            {--order-date= : Order date, for a single-PO export that does not carry one}
                            {--delivery-date= : The day it actually shipped, for a Noon picking list}';

    protected $description = 'Import a data file (master sheet, PO, packing list, sell-out, ...) from a path or URL';

    public function handle(UploadService $uploads): int
    {
        $type = UploadType::tryFrom((string) $this->argument('type'));

        if ($type === null) {
            $this->components->error('Unknown upload type "'.$this->argument('type').'".');
            $this->newLine();
            $this->components->twoColumnDetail('<fg=gray>TYPE</>', '<fg=gray>FILE</>');

            foreach (UploadType::cases() as $case) {
                $this->components->twoColumnDetail($case->value, $case->label());
            }

            return self::FAILURE;
        }

        $user = $this->uploader();

        if ($user === null) {
            return self::FAILURE;
        }

        $context = array_filter([
            'po_number' => $this->option('po'),
            'order_date' => $this->option('order-date'),
            'delivery_date' => $this->option('delivery-date'),
        ]);

        $failed = 0;

        foreach ($this->argument('source') as $source) {
            $failed += $this->importOne($uploads, $source, $type, $user, $context) ? 0 : 1;
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function importOne(UploadService $uploads, string $source, UploadType $type, User $user, array $context): bool
    {
        $this->components->task('Reading '.$this->shortName($source), function () use ($source, &$local) {
            $local = $this->fetch($source);

            return $local !== null;
        });

        if ($local === null) {
            return false;
        }

        try {
            $file = new UploadedFile($local, basename(parse_url($source, PHP_URL_PATH) ?: $source), null, null, true);

            $sourceFile = $uploads->handle($file, $type, $user, $context);
        } finally {
            @unlink($local);
        }

        $this->report($sourceFile);

        // A rejected or failed file must set a non-zero exit code, so that a scripted
        // reload of a staging environment stops rather than carrying on half-loaded.
        return ! in_array($sourceFile->status, [SourceFileStatus::Rejected, SourceFileStatus::Failed], true);
    }

    /**
     * Put the file somewhere real, whether it started as a path or a URL.
     *
     * Local files are COPIED rather than used in place, so that a source workbook
     * sitting in someone's home directory is never moved or consumed by an import.
     */
    private function fetch(string $source): ?string
    {
        $temp = tempnam(sys_get_temp_dir(), 'operon_import_');

        if (str_starts_with($source, 'http://') || str_starts_with($source, 'https://')) {
            $response = Http::timeout(120)->get($source);

            if (! $response->successful()) {
                @unlink($temp);
                $this->components->error('Download failed with HTTP '.$response->status().'.');

                return null;
            }

            file_put_contents($temp, $response->body());

            return $temp;
        }

        if (! is_file($source)) {
            @unlink($temp);
            $this->components->error('No such file: '.$source);

            return null;
        }

        copy($source, $temp);

        return $temp;
    }

    /** Who the audit log records as having loaded this. */
    private function uploader(): ?User
    {
        if ($email = $this->option('as')) {
            $user = User::where('email', $email)->first();

            if ($user === null) {
                $this->components->error('No user with the email '.$email.'.');
            }

            return $user;
        }

        $admin = User::role('Admin')->orderBy('id')->first();

        if ($admin === null) {
            $this->components->error('There is no Admin user to attribute the import to. Run `php artisan operon:bootstrap-admin` first, or pass --as=someone@example.com.');
        }

        return $admin;
    }

    private function report(SourceFile $file): void
    {
        $this->components->twoColumnDetail('Status', $file->status->label());
        $this->components->twoColumnDetail('Rows read', (string) ($file->rows_read ?? 0));
        $this->components->twoColumnDetail('Rows imported', (string) ($file->rows_imported ?? 0));

        if ($file->rows_skipped) {
            $this->components->twoColumnDetail('Rows skipped', (string) $file->rows_skipped);
        }

        if ($file->rows_unmatched) {
            $this->components->twoColumnDetail('Rows not matched to a product', (string) $file->rows_unmatched);
        }

        if ($file->rejection_reason) {
            $this->components->error($file->rejection_reason);
        }

        foreach ($file->warnings ?? [] as $warning) {
            $this->components->warn($warning);
        }
    }

    private function shortName(string $source): string
    {
        return basename(parse_url($source, PHP_URL_PATH) ?: $source);
    }
}
