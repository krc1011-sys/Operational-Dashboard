<?php

namespace App\Models;

use App\Enums\Channel;
use App\Enums\Marketplace;
use App\Enums\Stage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

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

    // --- Turnaround (§L) -----------------------------------------------------
    //
    // The headline KPI: days from the PO date to full fulfilment, against a 10-day
    // benchmark. The cached columns (first_shipped_on, completed_on, days_to_complete,
    // is_complete) are written by the Reconciler; what they MEAN is defined here.

    /**
     * The deliveries that actually shipped some of this PO.
     *
     * Linked through the final packing lines, because one delivery bundles several POs
     * and one PO spreads across several deliveries (§L: "searching a PO shows all
     * linked ISAs"). Interim deliveries are excluded on purpose - being booked is not
     * being shipped.
     *
     * @return Collection<int, Delivery>
     */
    public function shippedDeliveries()
    {
        return Delivery::query()
            ->whereIn('id', ShipmentLine::query()
                ->where('marketplace', $this->marketplace->value)
                ->where('po_number', $this->po_number)
                ->where('stage', Stage::Final->value)
                ->select('delivery_id'))
            ->orderBy('id')
            ->get();
    }

    /** Time-to-first-shipment, the responsiveness measure (§L). */
    public function computeFirstShippedOn(): ?Carbon
    {
        return $this->shipmentDates()->min();
    }

    /**
     * Is the PO finished? Complete when every line has shipped at least its net
     * accepted quantity - or has nothing left to ship because the remainder was
     * cancelled, which net accepted already accounts for (§L).
     *
     * A cancellation still waiting for a "deliver anyway / pull it" answer nets
     * nothing, so it correctly keeps the PO open until somebody answers.
     */
    public function computeIsComplete(): bool
    {
        return $this->lines()->exists()
            && ! $this->lines()->whereColumn('qty_shipped', '<', 'qty_net_accepted')->exists();
    }

    /**
     * The day the PO actually became complete.
     *
     * Usually the last shipment's date. But a PO can also be closed by a cancellation
     * taking away the remainder ("shipped 800 of 1,000 on the 5th, the other 200 were
     * cancelled on the 20th") - in which case it was not complete until the 20th. So
     * the answer is the later of the two events, and a PO closed purely by
     * cancellation still gets a date.
     */
    public function computeCompletedOn(): ?Carbon
    {
        $candidates = $this->shipmentDates();

        $lastCancellation = Cancellation::query()
            ->where('marketplace', $this->marketplace->value)
            ->where('po_number', $this->po_number)
            ->where('qty_honoured', '>', 0)
            ->get()
            ->map(fn (Cancellation $c) => $c->resolved_at ?? $c->imported_at)
            ->filter()
            ->map(fn ($at) => Carbon::parse($at)->startOfDay())
            ->max();

        if ($lastCancellation !== null) {
            $candidates->push($lastCancellation);
        }

        return $candidates->max();
    }

    /** Whole days from the PO date to completion. The headline turnaround figure (§L). */
    public function computeDaysToComplete(?Carbon $completedOn = null): ?int
    {
        $completedOn ??= $this->completed_on;

        if ($completedOn === null || $this->order_date === null) {
            return null;
        }

        return max(0, (int) round($this->order_date->diffInDays($completedOn)));
    }

    /** Secondary measure: how quickly the first units went out (§L). */
    public function daysToFirstShipment(): ?int
    {
        if ($this->first_shipped_on === null || $this->order_date === null) {
            return null;
        }

        return max(0, (int) round($this->order_date->diffInDays($this->first_shipped_on)));
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

        return max(0, (int) round($this->order_date->diffInDays(Carbon::today())));
    }

    /** The one number to show: days to complete, or days open so far. */
    public function turnaroundDays(): ?int
    {
        return $this->is_complete ? $this->days_to_complete : $this->daysOpen();
    }

    /** Past the 10-day benchmark, whether it has landed yet or not (§L). */
    public function isBreachingBenchmark(): bool
    {
        $days = $this->turnaroundDays();

        return $days !== null && $days > (int) config('operon.benchmarks.turnaround_days');
    }

    /**
     * The dates on which this PO's units actually left, one per shipped delivery.
     *
     * The date comes from the delivery itself: the final packing list's date, which §L
     * asks to be the exact delivery date, or the manually entered date for Noon, which
     * has no reliable date in the file (§Q). If a delivery somehow has neither, the
     * moment its final was uploaded stands in - we know it shipped, just not precisely
     * when, and that is far better than dropping the PO out of the turnaround figures.
     *
     * @return Collection<int, Carbon>
     */
    private function shipmentDates()
    {
        return $this->shippedDeliveries()
            ->map(fn (Delivery $delivery) => $delivery->fulfilmentDate())
            ->filter()
            ->values();
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
