<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Bundle component (not sold standalone)" — M8.
 *
 * Some products in the catalog are never sold on their own: they go into a bundle, and
 * the bundle is what carries a price. Their channel row therefore has a real COST and a
 * selling price that means nothing, so the engine computes a margin that is arithmetic
 * over a fiction. `BD07903074` was the worst example — it topped the losing-SKU list at
 * −33.31%, a phantom loss for a product that has never been sold at a loss because it has
 * never been sold at all.
 *
 * A FLAG, NOT A DELETION, and not a change to any figure. The product keeps every cost and
 * purchase number it has, everywhere: on the master grid, in the PO cost stack, in a PO's
 * P&L, in exports. What the flag does is keep it out of MARGIN RANKINGS and LOSS
 * WATCHLISTS, where a meaningless percentage crowds out the real ones, and label its
 * margin "N/A — bundle component" instead of printing a number nobody should act on.
 *
 * Rolling a component's cost up into the bundle it belongs to is the real fix and is
 * Phase 2/3 work: it needs a bundle-to-component mapping the catalog does not yet carry.
 * This flag is what makes the margin screens honest in the meantime, and it is the same
 * column that mapping will hang off later.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->boolean('is_bundle_component')->default(false)->after('is_active');

            // Ranked screens filter on this on every query, so it is worth an index even
            // at 914 products - and the catalog is expected to grow, not shrink.
            $table->index('is_bundle_component');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex(['is_bundle_component']);
            $table->dropColumn('is_bundle_component');
        });
    }
};
