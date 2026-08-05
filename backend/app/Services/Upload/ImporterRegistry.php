<?php

namespace App\Services\Upload;

use App\Enums\UploadType;
use App\Services\Import\AmazonCancellationImporter;
use App\Services\Import\AmazonPackingListImporter;
use App\Services\Import\AmazonPoImporter;
use App\Services\Import\MasterSheetImporter;

/**
 * Maps an upload type to the parser that handles it.
 *
 * Adding a channel or a file type is a one-line change to this map.
 */
class ImporterRegistry
{
    /** @var array<string, class-string<Importer>> */
    private array $importers = [
        // M3 — Amazon
        UploadType::AmazonPoBulk->value => AmazonPoImporter::class,
        UploadType::AmazonPoSingle->value => AmazonPoImporter::class,
        UploadType::AmazonInterimPacking->value => AmazonPackingListImporter::class,
        UploadType::AmazonFinalPacking->value => AmazonPackingListImporter::class,
        UploadType::AmazonCancellations->value => AmazonCancellationImporter::class,

        // M6 — the master catalog and its unit economics (§S)
        UploadType::MasterSheet->value => MasterSheetImporter::class,

        // M8 — Noon, M9 — DFS and sell-out.
    ];

    public function has(UploadType $type): bool
    {
        return isset($this->importers[$type->value]);
    }

    public function for(UploadType $type): ?Importer
    {
        $class = $this->importers[$type->value] ?? null;

        return $class === null ? null : app($class);
    }
}
