<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cancellations from the uploaded template (blueprint §G).
 *
 * THIS TABLE IS THE ONLY THING THAT NETS ACCEPTED UNITS. The PO export's own
 * "Cancelled quantity" column is read-only information and is never used here.
 *
 * The interesting part is `resolution`. A cancellation that only touches not-yet-booked
 * units is applied automatically. One that would claw back units already booked into a
 * delivery or already shipped stops and asks the user:
 *
 *   "Deliver anyway" -> the units stay accepted and count as delivered, and the line
 *                       carries a chargeback-exposure warning.
 *   "Pull it"        -> remove from the delivery / reduce as normal.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cancellations', function (Blueprint $table) {
            $table->id();

            $table->string('marketplace', 20);
            $table->string('channel', 30);
            $table->string('po_number', 64);
            $table->string('sku_id', 64);

            $table->foreignId('po_line_id')->nullable()->constrained('po_lines')->nullOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->boolean('is_unmatched')->default(false)->index();

            // Display columns from the template.
            $table->string('external_id', 64)->nullable();  // barcode - display only (§B)
            $table->string('description')->nullable();

            // Cross-check against our stored accepted qty; warn on mismatch (§G).
            $table->integer('qty_confirmed')->nullable();
            // The figure we net by.
            $table->integer('qty_cancelled')->default(0);

            // --- Resolution (§G) ------------------------------------------------
            $table->string('resolution', 30)->default('applied')->index();
            // How many of the cancelled units actually reduced net accepted. Differs from
            // qty_cancelled when part of the line was already shipped and delivered anyway.
            $table->integer('qty_honoured')->default(0);
            $table->integer('qty_delivered_anyway')->default(0);
            $table->text('resolution_note')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();

            $table->foreignId('source_file_id')->nullable()->constrained('source_files')->nullOnDelete();
            $table->timestamp('imported_at')->nullable();
            $table->foreignId('imported_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            /*
             * Latest uploaded figure wins per PO+SKU, mirroring the PO upsert rule.
             * If cancellations ever need to accumulate across several emails for the
             * same line, this unique key is the thing to revisit - see DOCUMENTATION.md.
             */
            $table->unique(['marketplace', 'po_number', 'sku_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cancellations');
    }
};
