<?php

namespace App\Services\Upload;

use App\Enums\UploadType;

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
        // UploadType::AmazonPoBulk->value => AmazonPoImporter::class,   // M3
        // UploadType::AmazonInterimPacking->value => PackingListImporter::class,  // M3
        // ...
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
