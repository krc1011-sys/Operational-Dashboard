<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marketplace free text becomes TEXT, so a long title cannot fail an import.
 *
 * Every column below holds words written by somebody else - a product description
 * from the master sheet, a listing title as Amazon or Noon publishes it - and none of
 * them has ever been counted. SQLite does not count them either: it stores a
 * 700-character name in a VARCHAR(255) without a word. So the limit was invisible
 * until the strict MySQL this deploys to rejected the row and took the whole import
 * down with it.
 *
 * These are not edge cases in synthetic data. Two of the real sample files trip it:
 *
 *   products.name       one description carries fifty-two `_x000D_` escapes in a row,
 *                       inflating a kitchen towel to 700 characters. That junk is now
 *                       decoded at source in CellValue - but the column should not
 *                       have been the thing that caught it.
 *   sellout_rows.title  Noon's listing titles are genuine SEO copy and routinely run
 *                       past 255 characters. Nothing is wrong with that data at all;
 *                       255 was simply the wrong size for it.
 *
 * A title has no natural maximum, so it should not carry an arbitrary one. None of
 * these columns is indexed, so there is nothing to give up by widening them.
 */
return new class extends Migration
{
    /** table => columns that hold marketplace-supplied free text. */
    private const COLUMNS = [
        'products' => ['name', 'short_description'],
        'product_identifiers' => ['title'],
        'po_lines' => ['title'],
        'shipment_lines' => ['title'],
        'sellout_rows' => ['title'],
        'inventory_snapshots' => ['title'],
        'cancellations' => ['description'],
        'dfs_orders' => ['description'],
    ];

    public function up(): void
    {
        $this->changeTo('text');
    }

    public function down(): void
    {
        $this->changeTo('string');
    }

    private function changeTo(string $type): void
    {
        foreach (self::COLUMNS as $table => $columns) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) use ($table, $columns, $type) {
                foreach ($columns as $column) {
                    if (Schema::hasColumn($table, $column)) {
                        $blueprint->{$type}($column)->nullable()->change();
                    }
                }
            });
        }
    }
};
