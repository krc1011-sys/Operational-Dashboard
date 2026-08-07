<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M9 — sell-out and inventory, across all three channels (§P, §R).
 *
 * Two things happen here.
 *
 * 1. `sellout_rows` stops being an Amazon-shaped table and becomes THE sell-out fact
 *    table for every channel. That means two additions that are not cosmetic:
 *
 *    - A GRAIN. Amazon's report is one row per ASIN aggregated over a reporting window;
 *      Noon's and DFS's are one row per SKU PER DAY. Both are sell-out and both belong
 *      here, but a reader who does not know which is which will double-count or
 *      divide by the wrong number of days. So the grain is stored, not inferred.
 *
 *    - AN EXPLICIT `revenue` COLUMN, because "revenue" means a different column in
 *      each file and one of them is a trap:
 *
 *          Amazon  "Shipped COGS"     = what Amazon PAID US        → ours
 *          Amazon  "Shipped Revenue"  = what the CUSTOMER paid     → NOT ours
 *          DFS     "Invoice amount"   = what we invoiced Amazon    → ours
 *          Noon    "GMV"              = gross merchandise value    → ours
 *
 *      On the real file those two Amazon columns are AED 1,704,390 and AED 1,691,050 —
 *      close enough that a mix-up would never look wrong, and be wrong on every screen
 *      it touches. `revenue` holds OUR figure whatever the file called it, and
 *      `revenue_basis` records which column it came from so the choice is auditable.
 *      `shipped_revenue` keeps Amazon's consumer price for context and is never summed
 *      as ours.
 *
 * 2. `inventory_snapshots` is new: stock on hand per channel, per day, with the extra
 *    signals each channel gives us. It is a SNAPSHOT table — one row per (channel, sku,
 *    date) — because stock is a level, not a flow, and re-uploading today's file must
 *    replace today's answer rather than add to it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sellout_rows', function (Blueprint $table) {
            /*
             * 'period' — one row covering period_start..period_end (Amazon's report).
             * 'day'    — one row for a single day (Noon's L60 feed, DFS's dated orders).
             * Days-of-cover divides by a run rate, and the run rate is derived
             * differently for each, so nothing may guess this.
             */
            $table->string('grain', 10)->default('period')->after('channel');

            // Noon reports sell-out against a BARCODE, not against its own NIN. Both are
            // kept: the normalised key for joining, and the barcode as printed for display.
            $table->string('barcode', 64)->nullable()->after('sku_id');
            $table->string('barcode_key', 64)->nullable()->after('barcode');
            $table->string('sku_id_type', 20)->nullable()->after('barcode_key'); // asin | nin | barcode

            // OUR revenue, whatever the file called it — see the class comment.
            $table->decimal('revenue', 16, 4)->nullable()->after('shipped_cogs');
            $table->string('revenue_basis', 30)->nullable()->after('revenue');

            $table->index(['channel', 'period_start']);
            $table->index('barcode_key');
        });

        /*
         * The uniqueness rule has to carry the channel now. Before M9 only Amazon Retail
         * wrote here, so (marketplace, sku, window) was enough; with DFS also under the
         * amazon marketplace, the same ASIN on the same day in both channels would have
         * collided and one channel would have silently overwritten the other.
         */
        Schema::table('sellout_rows', function (Blueprint $table) {
            $table->dropUnique('sellout_unique_period');
        });

        Schema::table('sellout_rows', function (Blueprint $table) {
            $table->unique(['channel', 'sku_id', 'period_start', 'period_end'], 'sellout_unique_channel_period');
        });

        Schema::create('inventory_snapshots', function (Blueprint $table) {
            $table->id();

            $table->string('marketplace', 20);
            $table->string('channel', 30);
            $table->string('sku_id', 64);            // ASIN (Amazon, DFS) or NIN (Noon)
            $table->string('sku_id_type', 20)->nullable();
            $table->string('barcode', 64)->nullable();
            $table->string('barcode_key', 64)->nullable()->index();

            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->boolean('is_unmatched')->default(false)->index();

            $table->string('title')->nullable();
            $table->string('brand')->nullable();

            $table->date('snapshot_date')->index();

            // --- The one figure every channel gives us -------------------------
            $table->integer('soh_units')->nullable();       // sellable stock on hand
            $table->decimal('soh_value', 16, 4)->nullable();

            // --- Amazon Retail only (§P) ---------------------------------------
            // Aged stock is the overstock signal that needs no arithmetic at all: it is
            // Amazon telling us this stock has sat for 90 days.
            $table->integer('aged_90_units')->nullable();
            $table->decimal('aged_90_value', 16, 4)->nullable();
            $table->integer('open_po_units')->nullable();        // in flight towards Amazon
            $table->integer('net_received_units')->nullable();   // their sell-in cross-check
            $table->decimal('net_received_value', 16, 4)->nullable();
            $table->integer('unsellable_units')->nullable();
            $table->decimal('receive_fill_pct', 8, 4)->nullable();
            $table->decimal('vendor_confirmation_pct', 8, 4)->nullable();
            $table->decimal('vendor_lead_time_days', 8, 2)->nullable();

            /*
             * The channel's OWN run rate, where it gives us one. Noon publishes L7_DRR,
             * a 7-day daily run rate, and a number the channel computed from its own
             * complete order book beats one we derive from a 60-day extract. Null
             * everywhere else, and the velocity engine derives one instead.
             */
            $table->decimal('daily_run_rate', 12, 4)->nullable();

            // --- DFS (§R) -------------------------------------------------------
            $table->string('warehouse_code', 40)->nullable();
            $table->string('warehouse_name')->nullable();
            $table->string('status', 30)->nullable();

            /*
             * DFS STOCK IS PROVISIONAL, AND SAYS SO IN THE DATA.
             *
             * The DFS bulk file is a current snapshot from Amazon's side, not from our own
             * warehouse system. It is ingested so DFS cover can be displayed at all, but
             * the real DFS stock position waits on the OperON ↔ in-house-tool integration.
             * The flag rides on the row rather than being inferred from the channel, so
             * every screen that shows the number can label it without knowing why.
             */
            $table->boolean('is_provisional')->default(false);
            $table->string('provisional_note')->nullable();

            $table->string('currency', 8)->default('AED');

            $table->foreignId('source_file_id')->nullable()->constrained('source_files')->nullOnDelete();
            $table->timestamp('imported_at')->nullable();
            $table->foreignId('imported_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // Stock is a level: one answer per SKU per channel per day.
            $table->unique(['channel', 'sku_id', 'snapshot_date'], 'inventory_unique_snapshot');
            $table->index(['channel', 'snapshot_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_snapshots');

        Schema::table('sellout_rows', function (Blueprint $table) {
            $table->dropUnique('sellout_unique_channel_period');
            $table->dropIndex(['channel', 'period_start']);
            $table->dropIndex(['barcode_key']);
            $table->dropColumn([
                'grain', 'barcode', 'barcode_key', 'sku_id_type', 'revenue', 'revenue_basis',
            ]);
            $table->unique(['marketplace', 'sku_id', 'period_start', 'period_end'], 'sellout_unique_period');
        });
    }
};
