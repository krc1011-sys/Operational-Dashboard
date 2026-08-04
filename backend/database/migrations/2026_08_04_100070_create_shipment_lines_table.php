<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One item row from a packing/picking list (blueprint §K, §Q).
 *
 * Deliberately NOT unique on (po, sku): the same ASIN legitimately appears on several
 * rows when it is split across cartons. Booked/shipped quantity is the SUM of the rows
 * for that PO+SKU, so each row is kept exactly as the file had it.
 *
 * Rows whose title is literally "Carton total" are per-carton subtotals for the packer
 * and are skipped at parse time - importing them would double-count (§K).
 *
 * `po_line_id` is nullable because a packing list can legitimately arrive before its PO
 * has been uploaded. Those rows are stored anyway, flagged unmatched, and auto-reconciled
 * when the PO turns up (§K).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipment_lines', function (Blueprint $table) {
            $table->id();

            $table->foreignId('delivery_id')->constrained('deliveries')->cascadeOnDelete();

            $table->string('marketplace', 20);
            $table->string('channel', 30);
            $table->string('stage', 12);            // interim | final
            $table->string('po_number', 64);
            $table->string('sku_id', 64);           // ASIN or NIN

            $table->foreignId('po_line_id')->nullable()->constrained('po_lines')->nullOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            // True while the PO for this line has not been ingested yet - not an error (§K).
            $table->boolean('is_unmatched')->default(false)->index();

            $table->integer('qty')->default(0);
            $table->string('carton', 64)->nullable();   // may be a range, e.g. "12-15"

            // Display only.
            $table->string('title')->nullable();
            $table->string('model_number')->nullable();  // the packing list's barcode column

            // Unit cost is hidden on the interim sheet and visible on the final; the
            // final's line value is what feeds the invoice total and the accounts.
            $table->decimal('unit_cost', 14, 4)->nullable();
            $table->decimal('line_value', 16, 4)->nullable();
            $table->string('currency', 8)->default('AED');

            // One invoice number per PO on the final packing list, e.g. "BD-1234-PO".
            // Stored as-is for the QuickBooks link (§K).
            $table->string('invoice_number', 64)->nullable()->index();

            $table->unsignedInteger('source_row')->nullable(); // row number in the sheet, for tracing

            $table->foreignId('source_file_id')->nullable()->constrained('source_files')->nullOnDelete();
            $table->timestamp('imported_at')->nullable();
            $table->foreignId('imported_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['marketplace', 'po_number', 'sku_id', 'stage']);
            $table->index(['delivery_id', 'stage']);
            $table->index(['channel', 'stage']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipment_lines');
    }
};
