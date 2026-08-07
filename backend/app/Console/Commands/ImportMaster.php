<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * The one import a new environment always needs, by name.
 *
 * The Master Products Sheet is not just another file: it is the catalog every other
 * upload joins onto. Load it first and a PO knows what it ordered; load it later and
 * the same PO sits there with unmatched identifiers until the master arrives. So it
 * gets its own command rather than being one value in a dropdown of fourteen.
 *
 *   php artisan operon:import-master ./OperON_Master_Merged.xlsx
 *   php artisan operon:import-master "https://.../OperON_Master_Merged.xlsx"
 *
 * Everything else goes through `operon:import <type> <file>`.
 */
class ImportMaster extends Command
{
    protected $signature = 'operon:import-master
                            {source : A local path or https URL to the Master Products Sheet}
                            {--as= : Email of the user to record as the uploader (default: the first Admin)}';

    protected $description = 'Import the Master Products Sheet - the catalog every other upload joins onto';

    public function handle(): int
    {
        return $this->call('operon:import', [
            'type' => 'master_sheet',
            'source' => [$this->argument('source')],
            '--as' => $this->option('as'),
        ]);
    }
}
