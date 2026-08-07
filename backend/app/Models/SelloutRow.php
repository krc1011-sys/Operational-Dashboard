<?php

namespace App\Models;

use App\Enums\Channel;
use App\Enums\Marketplace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Sell-out — what a channel's customers actually bought (§P, M9).
 *
 * ONE TABLE FOR ALL THREE CHANNELS, at two grains:
 *
 *   GRAIN_PERIOD  Amazon Retail. One row per ASIN, aggregated over the report's own
 *                 "Viewing Range". There is no daily detail in the file, so a run rate
 *                 from it is a PERIOD AVERAGE and is labelled as one everywhere.
 *   GRAIN_DAY     Noon Retail and Amazon DFS. One row per SKU per day, so a real L7 /
 *                 L30 run rate can be derived.
 *
 * ═══ THE COLUMN THAT IS A TRAP ═══
 *
 * `revenue` is OUR revenue and is the only column any screen should sum. Each file calls
 * it something different, and Amazon's report carries both ours and the customer's side
 * by side:
 *
 *     Shipped COGS     what Amazon PAID US    → ours, and what `revenue` holds
 *     Shipped Revenue  what the CUSTOMER paid → NOT ours, kept for context only
 *
 * On the real file those are AED 1,704,390.15 and AED 1,691,050.50. They are close
 * enough that using the wrong one would never look obviously wrong — which is exactly
 * why the right one is a named column rather than a convention.
 */
class SelloutRow extends Model
{
    use HasFactory;

    /** One row covering a reporting window (Amazon's aggregate report). */
    public const GRAIN_PERIOD = 'period';

    /** One row for a single day (Noon's L60 feed, DFS's dated orders). */
    public const GRAIN_DAY = 'day';

    /** Which column of the source file `revenue` was taken from. */
    public const BASIS_SHIPPED_COGS = 'shipped_cogs';

    public const BASIS_INVOICE_AMOUNT = 'invoice_amount';

    public const BASIS_GMV = 'gmv';

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
            'revenue' => 'decimal:4',
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

    /** Rows whose period overlaps the given range at all — the right test for a filter. */
    public function scopeOverlapping(Builder $query, $from, $to): Builder
    {
        return $query
            ->when($from, fn ($q) => $q->whereDate('period_end', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('period_start', '<=', $to));
    }

    public function scopeDaily(Builder $query): Builder
    {
        return $query->where('grain', self::GRAIN_DAY);
    }

    /**
     * How many days this row covers. A period row states its own window; a daily row is
     * one day. This is the denominator of every run rate, so it is never assumed.
     */
    public function days(): int
    {
        if ($this->grain === self::GRAIN_DAY || $this->period_start === null || $this->period_end === null) {
            return 1;
        }

        return max(1, (int) $this->period_start->diffInDays($this->period_end) + 1);
    }

    /**
     * Amazon's consumer-side figure, for display beside ours.
     *
     * Deliberately a method rather than a column any aggregate can reach by accident:
     * this is what the END CUSTOMER paid, and it is not money we ever see.
     */
    public function consumerRetailRevenue(): ?float
    {
        return $this->shipped_revenue === null ? null : (float) $this->shipped_revenue;
    }
}
