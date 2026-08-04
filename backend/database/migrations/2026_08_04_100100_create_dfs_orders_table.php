<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Amazon Direct Fulfilment orders (blueprint §R) - the third channel.
 *
 * These are real end-customer orders that Amazon routes to us to fulfil from our own
 * stock. There is no PO and no fill rate here: it is a straight sales/revenue feed by
 * ASIN over time. Outbound only - the file carries no returns.
 *
 * Its main operational job is feeding the "upcoming committed deliveries" lookup, which
 * stops the team ordering DFS holding stock for SKUs already booked to ship on a PO
 * next week.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dfs_orders', function (Blueprint $table) {
            $table->id();

            $table->string('marketplace', 20);
            $table->string('channel', 30);      // always amazon_dfs

            $table->string('order_id', 64)->index();
            $table->string('invoice_number', 64)->nullable()->index(); // BD-DFS-####
            $table->date('invoice_date')->nullable()->index();

            $table->string('sku_id', 64);       // ASIN
            $table->string('seller_sku', 64)->nullable();
            $table->string('description')->nullable();

            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->boolean('is_unmatched')->default(false)->index();

            $table->integer('qty')->default(0);
            $table->decimal('invoice_amount', 16, 4)->nullable();
            $table->string('currency', 8)->default('AED');

            $table->foreignId('source_file_id')->nullable()->constrained('source_files')->nullOnDelete();
            $table->timestamp('imported_at')->nullable();
            $table->foreignId('imported_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // Monthly files overlap, so re-uploading must not duplicate an order line.
            $table->unique(['order_id', 'sku_id']);
            $table->index(['sku_id', 'invoice_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dfs_orders');
    }
};
