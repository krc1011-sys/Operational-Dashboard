<?php

namespace App\Enums;

/**
 * The reporting/channel dimension (blueprint §R). Every fact row carries one so the
 * §M channel selector ("Amazon Retail / Amazon DFS / Noon, and combinations") works
 * on every screen.
 *
 * Tradeling / Noon Bulk / Noon SC exist in the catalog but stay dormant in Phase 1 (§S).
 */
enum Channel: string
{
    case AmazonRetail = 'amazon_retail';
    case AmazonDfs = 'amazon_dfs';
    case NoonRetail = 'noon_retail';

    public function label(): string
    {
        return match ($this) {
            self::AmazonRetail => 'Amazon Retail',
            self::AmazonDfs => 'Amazon DFS',
            self::NoonRetail => 'Noon Retail',
        };
    }

    public function marketplace(): Marketplace
    {
        return match ($this) {
            self::AmazonRetail, self::AmazonDfs => Marketplace::Amazon,
            self::NoonRetail => Marketplace::Noon,
        };
    }

    /** Channels that run through the PO → packing list → fill rate engine. */
    public function hasPurchaseOrders(): bool
    {
        // DFS is direct end-customer orders: no PO, no fill rate (§R).
        return $this !== self::AmazonDfs;
    }
}
