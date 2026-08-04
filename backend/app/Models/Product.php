<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A physical product in the master catalog, keyed by Company Product Code (§S).
 *
 * Money attributes on this model are visibility-restricted: `view-sku-cost`,
 * `view-sku-price` and `view-margin` decide who may see them, and money screens
 * additionally sit behind the PIN. Never render them without checking.
 */
class Product extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    /** Columns that must never be shown without a money permission (§O, §S). */
    public const COST_ATTRIBUTES = [
        'invoice_cost_price', 'product_cost', 'cogs', 'supplier_name',
    ];

    public const PRICE_ATTRIBUTES = [
        'rsp', 'net_receivable',
    ];

    public const MARGIN_ATTRIBUTES = [
        'profit', 'profit_pct', 'margin_pct', 'fulfilment_fee', 'referral_fee',
        'storage_fee', 'category_fee', 'other_fee', 'platform_fees_pct',
        'marketing', 'opex', 'packaging',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'cost_updated_at' => 'datetime',
            'extra' => 'array',
            'invoice_cost_price' => 'decimal:4',
            'product_cost' => 'decimal:4',
            'rsp' => 'decimal:4',
            'fulfilment_fee' => 'decimal:4',
            'referral_fee' => 'decimal:4',
            'storage_fee' => 'decimal:4',
            'category_fee' => 'decimal:4',
            'other_fee' => 'decimal:4',
            'platform_fees_pct' => 'decimal:4',
            'marketing' => 'decimal:4',
            'opex' => 'decimal:4',
            'packaging' => 'decimal:4',
            'net_receivable' => 'decimal:4',
            'cogs' => 'decimal:4',
            'profit' => 'decimal:4',
            'profit_pct' => 'decimal:4',
            'margin_pct' => 'decimal:4',
        ];
    }

    public function identifiers(): HasMany
    {
        return $this->hasMany(ProductIdentifier::class);
    }

    public function poLines(): HasMany
    {
        return $this->hasMany(PoLine::class);
    }

    public function shipmentLines(): HasMany
    {
        return $this->hasMany(ShipmentLine::class);
    }

    public function dfsOrders(): HasMany
    {
        return $this->hasMany(DfsOrder::class);
    }

    public function selloutRows(): HasMany
    {
        return $this->hasMany(SelloutRow::class);
    }

    public function sourceFile()
    {
        return $this->belongsTo(SourceFile::class);
    }
}
