<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M6 — what the real master sheet taught us about its own shape.
 *
 * M1 built `products` on the reasonable assumption that one product has one set of
 * economics. The real file says otherwise: it is one row per PRODUCT x CHANNEL, and a
 * product's economics genuinely differ per channel. The same BD code sells on Amazon VC
 * with a 29.65% platform fee and on Noon with 23.56%, at different invoice prices and
 * different marketing spend. 727 of the 914 products in the file appear on more than one
 * channel, and 641 ASINs are listed on both Amazon Retail and Amazon DFS.
 *
 * So the money columns move off `products` and onto their own table, one row per
 * (product, channel). What stays on `products` is what is true of the physical thing no
 * matter who sells it: its code, name, brand, category, sub-category, owner, origin,
 * barcode, suppliers and carton count.
 *
 * `product_identifiers` needs no change and that is the point: an identifier is per
 * MARKETPLACE (an ASIN is one ASIN whether it sells through VC or DFS), while economics
 * are per CHANNEL. Keeping the two apart is what lets 641 shared ASINs carry two sets of
 * numbers without either duplicating the identifier or losing a channel.
 *
 * Safe to run: `products` has never held a row, because the master sheet is loaded here
 * at M6 for the first time.
 */
return new class extends Migration
{
    /** The per-channel money columns M1 put on `products`, which move to the new table. */
    private const MOVED_COLUMNS = [
        'invoice_cost_price', 'rsp', 'fulfilment_fee', 'referral_fee', 'storage_fee',
        'category_fee', 'other_fee', 'platform_fees_pct', 'marketing', 'opex', 'packaging',
        'net_receivable', 'cogs', 'profit', 'profit_pct', 'margin_pct', 'currency',
    ];

    public function up(): void
    {
        Schema::create('product_channel_economics', function (Blueprint $table) {
            $table->id();

            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();

            // The reporting channel (§R): amazon_retail | amazon_dfs | noon_retail.
            $table->string('channel', 30)->index();

            // Who the channel is in the file's own words - kept so a row can always be
            // traced back to the line it came from.
            $table->string('customer_code', 40)->nullable();      // 1F6RD / TY7WK / LE3WVRU3GAE
            $table->string('customer_name')->nullable();          // "Amazon UAE - VC - 1F6RD"
            $table->string('customer_product_code', 64)->nullable(); // the ASIN or NIN as given

            // --- Sell side ----------------------------------------------------
            $table->decimal('rsp_with_vat', 14, 4)->nullable();
            $table->decimal('rsp_ex_vat', 14, 4)->nullable();
            $table->decimal('invoice_cost_price', 14, 4)->nullable(); // what we invoice the channel

            // --- Platform fees (§S) -------------------------------------------
            // The breakdown columns are almost entirely zero in the real file; the
            // headline percentage is what actually drives net receivable. Both are stored:
            // the breakdown so it can be filled in later, the percentage because it is
            // what the business currently works to.
            $table->decimal('fulfilment_fee', 14, 4)->nullable();
            $table->decimal('referral_fee', 14, 4)->nullable();
            $table->decimal('storage_fee', 14, 4)->nullable();
            $table->decimal('category_fee', 14, 4)->nullable();
            $table->decimal('other_fee', 14, 4)->nullable();
            $table->decimal('platform_fees_pct', 8, 6)->nullable(); // 0.296518 = 29.6518%

            // --- Cost stack ---------------------------------------------------
            // product_cost is repeated here as the file gave it for THIS channel. The
            // canonical per-product cost lives on `products`; where the two disagree
            // that is a data-quality signal, raised as a master anomaly rather than
            // silently averaged.
            $table->decimal('product_cost', 14, 4)->nullable();
            $table->decimal('marketing', 14, 4)->nullable();
            $table->decimal('opex', 14, 4)->nullable();
            $table->decimal('packaging', 14, 4)->nullable();
            $table->decimal('other_misc', 14, 4)->nullable();

            // --- Derived, as the SHEET calculated them ------------------------
            // Kept for cross-checking only. §S says the app's own calc is the source of
            // truth, so nothing reads these to make a decision (decision §10.9).
            $table->decimal('net_receivable_imported', 14, 4)->nullable();
            $table->decimal('cogs_imported', 14, 4)->nullable();
            $table->decimal('profit_imported', 14, 4)->nullable();
            $table->decimal('profit_pct_imported', 10, 6)->nullable();
            $table->decimal('margin_pct_imported', 10, 6)->nullable();

            // --- Derived, as WE calculate them (the source of truth) -----------
            $table->decimal('net_receivable', 14, 4)->nullable();
            $table->decimal('cogs', 14, 4)->nullable();
            $table->decimal('profit', 14, 4)->nullable();
            $table->decimal('profit_pct', 10, 6)->nullable();
            $table->decimal('margin_pct', 10, 6)->nullable();

            $table->string('currency', 8)->default('AED');

            // The merge tool's own review note, carried through verbatim (§S cleanup).
            $table->string('data_flag')->nullable();

            // Anything in the sheet we have not mapped to a column, so a re-import
            // never loses information.
            $table->json('extra')->nullable();

            $table->foreignId('source_file_id')->nullable()->constrained('source_files')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('is_manual')->default(false); // edited in the grid, not imported
            $table->timestamps();

            // One set of economics per product per channel.
            $table->unique(['product_id', 'channel']);
        });

        /*
         * The review queue. The master sheet is merged from several sources and carries
         * its own REVIEW flags; on top of that our own import finds things the file does
         * not flag. Both land here rather than being fixed silently, because a wrong
         * guess about which of two products a code means would quietly corrupt margin
         * for every PO that SKU appears on.
         */
        Schema::create('master_anomalies', function (Blueprint $table) {
            $table->id();

            $table->foreignId('source_file_id')->nullable()->constrained('source_files')->nullOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();

            $table->string('company_product_code', 40)->nullable()->index();
            $table->string('channel', 30)->nullable();

            $table->string('kind', 60)->index();      // see MasterAnomaly::KIND_*
            $table->string('severity', 20)->default('review'); // review | note
            $table->text('message');                  // written for a person, not a developer
            $table->json('details')->nullable();

            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('resolution_note')->nullable();

            $table->timestamps();

            $table->index(['resolved_at', 'severity']);
        });

        Schema::table('products', function (Blueprint $table) {
            // --- New identity/shared attributes from the merged master ---------
            $table->string('short_description')->nullable()->after('name');
            $table->string('sub_category')->nullable()->index()->after('category');  // APL Sub-Category
            $table->string('owner')->nullable()->index()->after('sub_category');     // APL Owner
            $table->string('origin')->nullable()->after('owner');                    // APL Origin
            // A product may be bought from several suppliers; the file lists them
            // comma-separated. Stored as given - see products.cost_basis for the §S rule.
            $table->text('suppliers')->nullable()->after('supplier_name');
            $table->unsignedInteger('cartons')->nullable()->after('suppliers');

            // Money moves to product_channel_economics.
            $table->dropColumn(self::MOVED_COLUMNS);
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['short_description', 'sub_category', 'owner', 'origin', 'suppliers', 'cartons']);

            $table->decimal('invoice_cost_price', 14, 4)->nullable();
            $table->decimal('rsp', 14, 4)->nullable();
            $table->decimal('fulfilment_fee', 14, 4)->nullable();
            $table->decimal('referral_fee', 14, 4)->nullable();
            $table->decimal('storage_fee', 14, 4)->nullable();
            $table->decimal('category_fee', 14, 4)->nullable();
            $table->decimal('other_fee', 14, 4)->nullable();
            $table->decimal('platform_fees_pct', 8, 4)->nullable();
            $table->decimal('marketing', 14, 4)->nullable();
            $table->decimal('opex', 14, 4)->nullable();
            $table->decimal('packaging', 14, 4)->nullable();
            $table->decimal('net_receivable', 14, 4)->nullable();
            $table->decimal('cogs', 14, 4)->nullable();
            $table->decimal('profit', 14, 4)->nullable();
            $table->decimal('profit_pct', 8, 4)->nullable();
            $table->decimal('margin_pct', 8, 4)->nullable();
            $table->string('currency', 8)->default('AED');
        });

        Schema::dropIfExists('master_anomalies');
        Schema::dropIfExists('product_channel_economics');
    }
};
