<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Amazon sell-out report (blueprint §P) - what Amazon sold on to end customers.
 *
 * Our SELL-IN is the final packing lists (us -> Amazon). This is the SELL-OUT
 * (Amazon -> customer). The ratio between them over a period is the Phase-2
 * sell-through metric; a low ratio means stock piling up at Amazon, which throttles
 * future POs and ties up cash on ~60-day terms.
 *
 * Joins by ASIN. Some rows carry only returns with blank sales, so every measure is
 * nullable rather than defaulted to zero - a blank and a real zero are different things.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sellout_rows', function (Blueprint $table) {
            $table->id();

            $table->string('marketplace', 20);
            $table->string('channel', 30);
            $table->string('sku_id', 64);       // ASIN

            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->boolean('is_unmatched')->default(false)->index();

            $table->string('title')->nullable();
            $table->string('brand')->nullable()->index();

            // The report's "Viewing Range" metadata from row 1.
            $table->date('period_start')->index();
            $table->date('period_end')->index();
            $table->timestamp('report_updated_at')->nullable(); // ~2-day lag

            $table->decimal('shipped_revenue', 16, 4)->nullable();
            $table->decimal('shipped_cogs', 16, 4)->nullable();
            $table->integer('shipped_units')->nullable();
            $table->integer('customer_returns')->nullable();
            $table->string('currency', 8)->default('AED');

            $table->foreignId('source_file_id')->nullable()->constrained('source_files')->nullOnDelete();
            $table->timestamp('imported_at')->nullable();
            $table->foreignId('imported_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // Re-uploading the same window replaces it rather than double-counting.
            $table->unique(['marketplace', 'sku_id', 'period_start', 'period_end'], 'sellout_unique_period');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sellout_rows');
    }
};
