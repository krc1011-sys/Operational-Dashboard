<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A physical product in the master catalog, keyed by Company Product Code (§S).
 *
 * This model holds what is true of the product no matter who sells it: its code, name,
 * brand, category, sub-category, owner, origin, barcode, suppliers and carton count.
 * Anything that changes per channel - price, fees, marketing, margin - lives on
 * ProductChannelEconomics, because the real master sheet is one row per product x
 * channel and the economics genuinely differ between them.
 *
 * The buy side stays here because §S's cost rule is per product, not per channel: a
 * product has several suppliers and we take the LATEST price (interim rule, flips to a
 * weighted average when Supplier-PO uploads land in Phase 3).
 *
 * `product_cost` and `supplier_name` are cost data: `view-sku-cost` decides who may see
 * them and money screens sit behind the PIN as well. Never render them without checking.
 */
class Product extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    /** Columns that must never be shown without a money permission (§O, §S). */
    public const COST_ATTRIBUTES = [
        'product_cost', 'supplier_name', 'suppliers',
    ];

    /** What the master grid lets an Admin edit by hand. Identity, not money. */
    public const EDITABLE = [
        'name', 'short_description', 'brand', 'category', 'sub_category', 'owner',
        'origin', 'barcode', 'suppliers', 'cartons', 'product_cost', 'is_active',
        // "Bundle component (not sold standalone)" (M8). Editable by hand because only a
        // person knows which products these are - nothing in the file says so.
        'is_bundle_component',
    ];

    /** How a bundle component's margin reads instead of a meaningless percentage. */
    public const BUNDLE_MARGIN_LABEL = 'N/A — bundle component';

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_bundle_component' => 'boolean',
            'cost_updated_at' => 'datetime',
            'extra' => 'array',
            'cartons' => 'integer',
            'product_cost' => 'decimal:4',
        ];
    }

    public function identifiers(): HasMany
    {
        return $this->hasMany(ProductIdentifier::class);
    }

    /** Unit economics, one row per channel this product sells on (§S). */
    public function economics(): HasMany
    {
        return $this->hasMany(ProductChannelEconomics::class);
    }

    public function anomalies(): HasMany
    {
        return $this->hasMany(MasterAnomaly::class);
    }

    /**
     * Products whose margin means something (M8).
     *
     * A bundle component is never sold on its own, so it has a real cost against a selling
     * price that was never charged - the engine's answer for it is arithmetic over a
     * fiction. Ranked margin screens and loss watchlists use this scope so a phantom loss
     * cannot crowd out a real one; COST screens deliberately do not, because what we paid
     * for the thing is a fact whichever way it is sold.
     */
    public function scopeRankableForMargin(Builder $query): Builder
    {
        return $query->where('is_bundle_component', false);
    }

    /** Does this product's margin mean anything? */
    public function hasMeaningfulMargin(): bool
    {
        return ! $this->is_bundle_component;
    }

    /**
     * The suppliers list as a real list. The sheet stores them comma-separated because
     * a product is often bought from more than one.
     */
    public function supplierList(): array
    {
        return collect(explode(',', (string) $this->suppliers))
            ->map(fn (string $s) => trim($s))
            ->filter()
            ->values()
            ->all();
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
