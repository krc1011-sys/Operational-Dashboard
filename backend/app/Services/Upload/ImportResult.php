<?php

namespace App\Services\Upload;

/**
 * What an import actually did (§J audit log).
 *
 * `unmatched` is deliberately a first-class count rather than an error: packing-list
 * lines whose PO has not been ingested yet are expected during rollout, are stored,
 * and reconcile later (§K). Reporting them keeps that visible without alarming anyone.
 */
class ImportResult
{
    public function __construct(
        public readonly int $rowsRead = 0,
        public readonly int $rowsImported = 0,
        public readonly int $rowsSkipped = 0,
        public readonly int $rowsUnmatched = 0,
        public readonly array $warnings = [],
        public readonly array $summary = [],
    ) {}
}
