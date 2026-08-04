<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The master product catalog (blueprint §S).
 *
 * `company_product_code` (BD#####) is the canonical key for a physical product. It is
 * what unifies an ASIN, a NIN and a DFS SKU into one product, which is why cross-channel
 * reporting works at all. Barcode is NEVER used for cross-platform linking (§B, §S).
 *
 * The money columns are the INPUTS to the true-net-margin calculation. Derived figures
 * (net receivable, COGS, profit, margin %) are also stored as imported for cross-checking,
 * but the app recomputes them with its own logic at M6 - the app is the source of truth (§S).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();

            // --- Identity -----------------------------------------------------
            $table->string('company_product_code', 40)->unique(); // BD##### - canonical key
            $table->string('name')->nullable();
            $table->string('brand')->nullable()->index();
            $table->string('category')->nullable()->index();
            $table->string('barcode', 64)->nullable()->index(); // display/search only (§B)
            $table->boolean('is_active')->default(true);

            // --- Buy side -----------------------------------------------------
            $table->decimal('invoice_cost_price', 14, 4)->nullable();
            $table->decimal('product_cost', 14, 4)->nullable();
            $table->string('supplier_name')->nullable();
            /*
             * §S cost rule (INTERIM): a product may have several suppliers - use the
             * LATEST price. Flips to a weighted average once Supplier-PO uploads exist
             * (Phase 3). Stored per-row so the change is a data migration, not a rewrite.
             */
            $table->string('cost_basis', 20)->default('latest');
            $table->timestamp('cost_updated_at')->nullable();

            // --- Sell side ----------------------------------------------------
            $table->decimal('rsp', 14, 4)->nullable(); // recommended selling price

            // --- Fees and overheads (§S) --------------------------------------
            $table->decimal('fulfilment_fee', 14, 4)->nullable();
            $table->decimal('referral_fee', 14, 4)->nullable();
            $table->decimal('storage_fee', 14, 4)->nullable();
            $table->decimal('category_fee', 14, 4)->nullable();
            $table->decimal('other_fee', 14, 4)->nullable();
            $table->decimal('platform_fees_pct', 8, 4)->nullable();
            $table->decimal('marketing', 14, 4)->nullable();
            $table->decimal('opex', 14, 4)->nullable();
            $table->decimal('packaging', 14, 4)->nullable();

            // --- Derived, as imported (app recomputes these at M6) -------------
            $table->decimal('net_receivable', 14, 4)->nullable();
            $table->decimal('cogs', 14, 4)->nullable();
            $table->decimal('profit', 14, 4)->nullable();
            $table->decimal('profit_pct', 8, 4)->nullable();
            $table->decimal('margin_pct', 8, 4)->nullable();

            $table->string('currency', 8)->default('AED');

            /*
             * The master sheet has 32 columns and will grow. Anything we have not mapped
             * to a real column is kept here so a re-import never loses information.
             */
            $table->json('extra')->nullable();

            $table->foreignId('source_file_id')->nullable()->constrained('source_files')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
