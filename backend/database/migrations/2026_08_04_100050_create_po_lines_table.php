<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per PO x SKU - the spine of the whole system (blueprint §C, §F).
 *
 * The join key is (marketplace, po_number, sku_id). Never barcode (§B).
 *
 * Note `qty_cancelled_po_file`: Amazon's PO export has its own "Cancelled quantity"
 * column, but it is NEVER used for netting - fresh POs always show 0 and real
 * cancellations only arrive days later by email (§G). It is stored as read-only
 * information and displayed as such. Netting comes solely from the `cancellations` table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('po_lines', function (Blueprint $table) {
            $table->id();

            $table->foreignId('purchase_order_id')->constrained('purchase_orders')->cascadeOnDelete();

            // Denormalised join key - kept on the line so packing lists, cancellations
            // and this table all match on identical (marketplace, po_number, sku_id).
            $table->string('marketplace', 20);
            $table->string('channel', 30);
            $table->string('po_number', 64);
            $table->string('sku_id', 64);           // ASIN (Amazon) or NIN (Noon)
            $table->string('sku_id_type', 20)->nullable();

            // Resolved through product_identifiers; null until the catalog knows the SKU.
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();

            // --- Display / search (§B) -----------------------------------------
            $table->string('title')->nullable();
            $table->string('barcode', 64)->nullable()->index();
            $table->string('external_id_type', 20)->nullable(); // UPC | EAN | SKU | ISBN
            $table->string('model_number')->nullable();
            $table->string('merchant_sku')->nullable();
            $table->string('availability')->nullable();         // e.g. "AC - Accepted: In stock"

            // --- Quantities ----------------------------------------------------
            $table->integer('qty_requested')->default(0);
            $table->integer('qty_accepted')->default(0);   // fill-rate denominator (§E)
            $table->integer('qty_asn')->default(0);
            $table->integer('qty_received')->default(0);
            $table->integer('qty_remaining')->default(0);
            // READ-ONLY INFO. Not used for netting - see the class comment above (§G).
            $table->integer('qty_cancelled_po_file')->default(0);

            // Noon has no accept-less-than-ordered step, so confirmation rate is
            // Amazon-only (§Q). For Noon, qty_requested == qty_accepted == UOM Qty.

            // --- Commercial ----------------------------------------------------
            $table->integer('case_size')->nullable();
            $table->decimal('unit_cost', 14, 4)->default(0);  // marketplace price
            $table->string('currency', 8)->default('AED');

            // --- Per-line dates, as they appear on the line (§C) ----------------
            $table->date('window_start')->nullable();
            $table->date('window_end')->nullable();
            $table->date('expected_date')->nullable();
            $table->string('ship_to_fc', 40)->nullable()->index();

            // --- Cached reconciliation results, recomputed at M4 (§F) -----------
            $table->integer('qty_cancelled_honoured')->default(0); // from the cancellation file
            $table->integer('qty_net_accepted')->default(0);       // accepted - honoured cancellations
            $table->integer('qty_booked')->default(0);             // sum of interim packing lines
            $table->integer('qty_shipped')->default(0);            // sum of final packing lines
            $table->integer('qty_not_booked')->default(0);         // net accepted - booked - cancelled
            $table->decimal('fill_rate_pct', 8, 4)->nullable();    // shipped / net accepted
            $table->string('line_state', 30)->nullable()->index(); // cancelled | not_booked | scheduled | dispatched
            // "cancellation received - shipped anyway" chargeback warning (§G).
            $table->boolean('has_chargeback_flag')->default(false)->index();

            $table->foreignId('source_file_id')->nullable()->constrained('source_files')->nullOnDelete();
            $table->timestamp('imported_at')->nullable();
            $table->foreignId('imported_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // Upsert key for re-uploads: latest snapshot wins, no duplicates (§C).
            $table->unique(['marketplace', 'po_number', 'sku_id']);
            $table->index(['marketplace', 'sku_id']);
            $table->index(['channel', 'line_state']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('po_lines');
    }
};
