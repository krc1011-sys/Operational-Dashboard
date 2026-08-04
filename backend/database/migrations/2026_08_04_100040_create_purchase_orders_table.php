<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PO header - one row per purchase order (blueprint §C, §L, §Q).
 *
 * The Amazon export is one row per PO x ASIN, so the PO-level facts (vendor code, order
 * date, ship-to FC, cancellation deadline) repeat on every line. Lifting them here gives
 * us a real place to hang PO-level KPIs: confirmation rate, days-to-first-shipment and
 * the headline turnaround (completion date - PO date, benchmark 10 days).
 *
 * The turnaround columns are CACHED results, recomputed by the M4 reconciliation engine.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_orders', function (Blueprint $table) {
            $table->id();

            $table->string('marketplace', 20);      // amazon | noon
            $table->string('channel', 30);          // amazon_retail | noon_retail
            $table->string('po_number', 64);

            $table->string('vendor_code', 40)->nullable();  // e.g. 1F6RD
            $table->string('status', 40)->nullable();       // e.g. Confirmed
            $table->string('ship_to_fc', 40)->nullable()->index();  // clean FC code, e.g. DXB3
            $table->string('ship_to_raw')->nullable();      // the untouched cell, for auditing
            $table->string('currency', 8)->default('AED');

            // --- Dates (§L: time-based reports anchor on the PO order date) ----
            $table->date('order_date')->nullable()->index();   // the "PO open date"
            $table->date('window_start')->nullable();
            $table->date('window_end')->nullable()->index();
            $table->date('expected_date')->nullable();
            $table->date('cancellation_deadline')->nullable();

            // --- Noon-specific (§Q) --------------------------------------------
            $table->date('approval_date')->nullable();
            $table->date('estimated_delivery_date')->nullable();
            /*
             * Noon files do not reliably carry the real delivery date, so it is captured
             * in the upload confirm step (pre-filled with the estimate, editable) and
             * remains editable later from the PO view if the delivery is rescheduled.
             */
            $table->date('delivery_date')->nullable();
            $table->boolean('delivery_date_is_manual')->default(false);

            // --- Cached turnaround results, recomputed at M4 (§L) --------------
            $table->date('first_shipped_on')->nullable();   // time-to-first-shipment
            $table->date('completed_on')->nullable();       // shipped >= net accepted, or remainder cancelled
            $table->unsignedSmallInteger('days_to_complete')->nullable();
            $table->boolean('is_complete')->default(false)->index();

            $table->foreignId('source_file_id')->nullable()->constrained('source_files')->nullOnDelete();
            $table->timestamp('imported_at')->nullable();
            $table->foreignId('imported_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // Re-uploading a PO file upserts on this key: latest snapshot wins (§C).
            $table->unique(['marketplace', 'po_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_orders');
    }
};
