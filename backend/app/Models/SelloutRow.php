<?php

namespace App\Models;

use App\Enums\Channel;
use App\Enums\Marketplace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One ASIN's sell-out figures for a reporting window (§P).
 */
class SelloutRow extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'marketplace' => Marketplace::class,
            'channel' => Channel::class,
            'period_start' => 'date',
            'period_end' => 'date',
            'report_updated_at' => 'datetime',
            'is_unmatched' => 'boolean',
            'imported_at' => 'datetime',
            'shipped_revenue' => 'decimal:4',
            'shipped_cogs' => 'decimal:4',
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

    public function scopeInPeriod(Builder $query, $from, $to): Builder
    {
        return $query->whereDate('period_start', '>=', $from)
            ->whereDate('period_end', '<=', $to);
    }
}
