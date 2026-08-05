<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Net margin, corrected: we are a VENDOR, not a Seller-Central seller.
 *
 * The first cut of M6 drove net receivable off the sheet's "Platform Total Fees %" as a
 * single blended number. That reproduced the sheet's arithmetic but described the wrong
 * business. On Amazon Vendor Central, Amazon DFS and Noon Retail the marketplace BUYS
 * from us, and what it keeps is a front margin (off the retail price, to reach the
 * invoice) and a back margin (off the invoice, to reach what we bank). The
 * Seller-Central fee columns in the sheet - fulfilment, referral, warehouse, category,
 * other - do not apply to us at all and are never deducted.
 *
 *     Invoice / PO value  = RSP ex VAT x invoice_pct_of_rsp
 *     Net receivable      = Invoice     x net_pct_of_invoice
 *
 * Both rates are stored PER ROW rather than assumed per channel, because the real file
 * proves they vary. The standard rates reconcile with the sheet's own columns exactly -
 * Amazon Retail 749 of 749 on both steps, Noon 533 of 533 on the invoice step - but 151
 * Noon rows keep 0.80 of the invoice instead of 0.78, and every one of them is
 * Category = FnB. Food carries a different back margin. Hardcoding 22% would have
 * silently overstated the marketplace's cut on all 151.
 *
 * So the rates are data: taken from the file where it states them, falling back to the
 * channel defaults in config/operon.php for a product typed into the grid by hand.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_channel_economics', function (Blueprint $table) {
            // What the invoice is worth as a share of the retail price, ex VAT.
            // 0.9019 on Amazon (VC and DFS), 0.98 on Noon.
            $table->decimal('invoice_pct_of_rsp', 8, 6)->nullable()->after('invoice_cost_price');

            // What we actually bank as a share of the invoice. 0.78 as standard;
            // 0.80 on Noon food (FnB).
            $table->decimal('net_pct_of_invoice', 8, 6)->nullable()->after('invoice_pct_of_rsp');

            // The invoice/PO value we compute, kept beside the file's own
            // `invoice_cost_price` so the two can be compared (§S: ours is the truth,
            // theirs is the cross-check).
            $table->decimal('invoice_value', 14, 4)->nullable()->after('net_pct_of_invoice');
        });
    }

    public function down(): void
    {
        Schema::table('product_channel_economics', function (Blueprint $table) {
            $table->dropColumn(['invoice_pct_of_rsp', 'net_pct_of_invoice', 'invoice_value']);
        });
    }
};
