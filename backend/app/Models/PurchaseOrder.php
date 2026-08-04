<?php

namespace App\Models;

use App\Enums\Channel;
use App\Enums\Marketplace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A purchase order header (§C, §L).
 */
class PurchaseOrder extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'marketplace' => Marketplace::class,
            'channel' => Channel::class,
            'order_date' => 'date',
            'window_start' => 'date',
            'window_end' => 'date',
            'expected_date' => 'date',
            'cancellation_deadline' => 'date',
            'approval_date' => 'date',
            'estimated_delivery_date' => 'date',
            'delivery_date' => 'date',
            'first_shipped_on' => 'date',
            'completed_on' => 'date',
            'delivery_date_is_manual' => 'boolean',
            'is_complete' => 'boolean',
            'imported_at' => 'datetime',
        ];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(PoLine::class);
    }

    public function sourceFile(): BelongsTo
    {
        return $this->belongsTo(SourceFile::class);
    }

    /**
     * The deliveries this PO's units went into (§L: "searching a PO shows all linked ISAs").
     * Linked through the packing lines rather than a direct foreign key, because one
     * delivery bundles several POs and one PO spreads across several deliveries.
     */
    public function deliveries()
    {
        return Delivery::query()
            ->whereIn('id', ShipmentLine::query()
                ->where('marketplace', $this->marketplace->value)
                ->where('po_number', $this->po_number)
                ->select('delivery_id'));
    }

    /** PO Confirmation Rate = accepted / requested (§L). Amazon only - Noon has no accept step (§Q). */
    public function confirmationRate(): ?float
    {
        if ($this->marketplace !== Marketplace::Amazon) {
            return null;
        }

        $requested = (int) $this->lines()->sum('qty_requested');

        return $requested > 0
            ? round($this->lines()->sum('qty_accepted') / $requested * 100, 2)
            : null;
    }

    /**
     * Days elapsed so far on an open PO - the "X days and counting" figure (§L).
     * Returns null once complete; use days_to_complete then.
     */
    public function daysOpen(): ?int
    {
        if ($this->is_complete || ! $this->order_date) {
            return null;
        }

        return $this->order_date->diffInDays(Carbon::today());
    }

    /** Past the 10-day benchmark and still not complete (§L). */
    public function scopeBreachingBenchmark(Builder $query): Builder
    {
        $benchmark = (int) config('operon.benchmarks.turnaround_days');

        return $query->where('is_complete', false)
            ->whereNotNull('order_date')
            ->whereDate('order_date', '<', Carbon::today()->subDays($benchmark));
    }
}
