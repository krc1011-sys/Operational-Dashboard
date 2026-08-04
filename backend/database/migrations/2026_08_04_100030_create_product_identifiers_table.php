<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Maps a marketplace's native product id to a catalog product (blueprint §S).
 *
 *   (amazon, B08XYZ...) -> product 1
 *   (noon,   Z8C550...) -> product 1     <- same physical product, one BD##### code
 *
 * Every fact table (po_lines, shipment_lines, cancellations, sellout_rows, dfs_orders)
 * stores its raw (marketplace, sku_id) exactly as the file gave it, plus a nullable
 * product_id resolved through this table. That is what lets us ingest a file for a
 * product the catalog has never seen, without dropping the row (§K).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_identifiers', function (Blueprint $table) {
            $table->id();

            // Nullable on purpose: an identifier can be seen in a file before the
            // master sheet knows about it. It gets linked when the catalog catches up.
            $table->foreignId('product_id')->nullable()->constrained('products')->cascadeOnDelete();

            $table->string('marketplace', 20);          // amazon | noon
            $table->string('sku_id', 64);               // the ASIN or NIN as it appears
            $table->string('sku_id_type', 20)->nullable(); // asin | nin

            // Display/search only - never a join key (§B).
            $table->string('barcode', 64)->nullable()->index();
            $table->string('seller_sku', 64)->nullable();
            $table->string('title')->nullable();

            $table->timestamps();

            // An ASIN is unique within Amazon; a NIN is unique within Noon.
            $table->unique(['marketplace', 'sku_id']);
            $table->index(['product_id', 'marketplace']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_identifiers');
    }
};
