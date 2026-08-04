<?php

namespace App\Models;

use App\Enums\Channel;
use App\Enums\Marketplace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One Direct Fulfilment order line (§R). No PO, no fill rate - a pure revenue feed.
 */
class DfsOrder extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'marketplace' => Marketplace::class,
            'channel' => Channel::class,
            'invoice_date' => 'date',
            'is_unmatched' => 'boolean',
            'imported_at' => 'datetime',
            'invoice_amount' => 'decimal:4',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function sourceFile(): BelongsTo
    {
        return $this->belongsTo(SourceFile::class);
    }

    public function scopeInvoicedBetween(Builder $query, $from, $to): Builder
    {
        return $query->whereBetween('invoice_date', [$from, $to]);
    }
}
