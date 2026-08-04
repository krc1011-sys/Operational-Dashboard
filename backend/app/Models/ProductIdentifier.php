<?php

namespace App\Models;

use App\Enums\Marketplace;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Maps a marketplace-native id (ASIN / NIN) to a catalog product (§S).
 */
class ProductIdentifier extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'marketplace' => Marketplace::class,
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Resolve a raw (marketplace, sku_id) pair to a product id, or null if the catalog
     * has not seen it yet. Callers store the row regardless - nothing is ever dropped (§K).
     */
    public static function resolveProductId(Marketplace|string $marketplace, string $skuId): ?int
    {
        $value = $marketplace instanceof Marketplace ? $marketplace->value : $marketplace;

        return static::query()
            ->where('marketplace', $value)
            ->where('sku_id', $skuId)
            ->value('product_id');
    }
}
