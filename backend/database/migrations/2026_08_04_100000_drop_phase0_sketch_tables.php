<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * M1 — clears the Phase-0 sketch schema.
 *
 * The six tables below were a first draft written before the blueprint was complete.
 * They are replaced by the M1 model (products, product_identifiers, purchase_orders,
 * po_lines, deliveries, shipment_lines, cancellations, sellout_rows, dfs_orders,
 * source_files), which adds everything the blueprint needs and the sketch lacked:
 * the ASN/delivery concept, requested-vs-accepted quantities, PO headers, the channel
 * dimension, Company Product Code as the catalog key, and the sell-out / DFS feeds.
 *
 * Safe to drop: these tables were never populated with real data. Their original
 * migration files have been removed, so `migrate:fresh` will not recreate them.
 */
return new class extends Migration
{
    private const LEGACY_TABLES = [
        'shipment_lines',   // recreated below with a real delivery link
        'cancelled_items',  // becomes `cancellations` with the deliver-anyway flag
        'line_items',       // becomes `po_lines` + `purchase_orders`
        'master_sku_rows',  // becomes `products` + `product_identifiers`
        'manual_overrides', // unused and not in the blueprint - see DOCUMENTATION.md §5
        'source_files',     // recreated below as the full upload audit log
    ];

    public function up(): void
    {
        foreach (self::LEGACY_TABLES as $table) {
            Schema::dropIfExists($table);
        }
    }

    public function down(): void
    {
        // Deliberately irreversible: the sketch schema is not something to roll back to.
    }
};
