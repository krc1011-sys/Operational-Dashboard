<?php

namespace App\Services\Upload;

use App\Enums\UploadType;
use App\Services\Import\AmazonCancellationImporter;
use App\Services\Import\AmazonPackingListImporter;
use App\Services\Import\AmazonPoImporter;

/**
 * Maps an upload type to the parser that handles it.
 *
 * Empty until M3, which registers the Amazon PO, packing-list and cancellation
 * importers here. Adding a channel later is a one-line change to this map.
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

        // M8 — Noon, M9 — DFS and sell-out, M6 — master sheet.
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
