<?php

namespace App\Models;

use App\Enums\Channel;
use App\Enums\Marketplace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Stock on hand at one channel, on one day (M9, §P/§R).
 *
 * A SNAPSHOT, not a movement: stock is a level, so one row per (channel, SKU, day) and
 * re-uploading today's file replaces today's answer rather than adding to it.
 *
 * The three channels give us very different amounts of information, and the model does
 * not pretend otherwise — a column a channel does not publish stays null rather than
 * being defaulted to zero, because "we hold none" and "they did not say" lead to
 * opposite decisions on a stock screen.
 *
 *   Amazon Retail  the richest: sellable SOH, aged 90+, open PO, net received,
 *                  receive fill %, vendor lead time
 *   Noon Retail    SOH plus Noon's OWN 7-day daily run rate (L7_DRR)
 *   Amazon DFS     available units only, and PROVISIONAL — see $is_provisional
 */
class InventorySnapshot extends Model
{
    use HasFactory;

    /** What every DFS row is labelled with, until the in-house tool is wired in. */
    public const DFS_PROVISIONAL_NOTE = 'provisional — pending internal-tool link';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'marketplace' => Marketplace::class,
            'channel' => Channel::class,
            'snapshot_date' => 'date',
            'is_unmatched' => 'boolean',
            'is_provisional' => 'boolean',
            'imported_at' => 'datetime',
            'soh_value' => 'decimal:4',
            'aged_90_value' => 'decimal:4',
            'net_received_value' => 'decimal:4',
            'receive_fill_pct' => 'decimal:4',
            'vendor_confirmation_pct' => 'decimal:4',
            'vendor_lead_time_days' => 'decimal:2',
            'daily_run_rate' => 'decimal:4',
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

    /**
     * The most recent snapshot date we hold for a channel, or for everything, as Y-m-d.
     *
     * Every stock screen is "as at" a date, and the honest date is the one in the file
     * rather than today. Deliberately not defaulted to today when nothing is loaded:
     * null means no stock has been ingested, and the screens say so.
     *
     * ═══ WHY IT IS NORMALISED HERE ═══
     *
     * The importers write this column with a bulk `insert()` for speed, which stores the
     * plain "2026-08-05" they were given. Anything written through `create()` goes via
     * the date cast and stores "2026-08-05 00:00:00". `max()` returns whichever it finds,
     * and feeding the timestamp form back into `whereDate()` compares
     * date(column) = "2026-08-05 00:00:00" — which matches NOTHING.
     *
     * The failure is silent and total: every stock figure vanishes, cover reads "—"
     * everywhere, and it depends on nothing more than how the row happened to be written.
     * So the date is reduced to Y-m-d once, here, where every caller gets it.
     */
    public static function latestDateFor(?Channel $channel = null): ?string
    {
        $value = static::query()
            ->when($channel, fn ($q) => $q->where('channel', $channel->value))
            ->max('snapshot_date');

        return $value === null ? null : Carbon::parse($value)->toDateString();
    }

    /** Only the newest snapshot per channel — what "stock now" means. */
    public function scopeLatest(Builder $query, ?Channel $channel = null): Builder
    {
        $date = self::latestDateFor($channel);

        return $query
            ->when($channel, fn ($q) => $q->where('channel', $channel->value))
            ->when($date, fn ($q) => $q->whereDate('snapshot_date', $date));
    }

    /** Amazon's own overstock signal: stock that has sat for 90 days. */
    public function scopeAged(Builder $query): Builder
    {
        return $query->where('aged_90_units', '>', 0);
    }
}
