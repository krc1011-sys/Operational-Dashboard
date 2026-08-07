<?php

namespace App\Models;

use App\Enums\Channel;
use App\Enums\Marketplace;
use App\Enums\Stage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One delivery, identified by its ASN (§K, §Q).
 */
class Delivery extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'marketplace' => Marketplace::class,
            'channel' => Channel::class,
            'planned_date' => 'date',
            'delivered_on' => 'date',
            'delivery_date_is_manual' => 'boolean',
            'has_interim' => 'boolean',
            'has_final' => 'boolean',
            'has_fc_conflict' => 'boolean',
            'interim_uploaded_at' => 'datetime',
            'final_uploaded_at' => 'datetime',
            'value_interim' => 'decimal:4',
            'value_final' => 'decimal:4',
            'shortfall_value' => 'decimal:4',
        ];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(ShipmentLine::class);
    }

    public function interimLines(): HasMany
    {
        return $this->lines()->where('stage', Stage::Interim->value);
    }

    public function finalLines(): HasMany
    {
        return $this->lines()->where('stage', Stage::Final->value);
    }

    /** The distinct POs bundled into this delivery. One delivery = one FC (§A). */
    public function poNumbers(): array
    {
        return $this->lines()->distinct()->pluck('po_number')->all();
    }

    /**
     * Find or create the delivery a packing list belongs to. Amazon keys on the ASN
     * parsed from the banner; Noon has no ASN in the file, so a stand-in key is used (§Q).
     */
    public static function keyFor(Marketplace $marketplace, ?string $asn, string $fallback): string
    {
        if (filled($asn)) {
            return $asn;
        }

        return strtoupper($marketplace->value).':'.$fallback;
    }

    /** How an unconfirmed Noon date is described, everywhere it is shown (M9). */
    public const ESTIMATED_LABEL = 'estimated (not confirmed)';

    /**
     * The date this delivery actually went out - what turnaround is measured against (§L).
     *
     * Null until a final packing list exists, because being booked into a delivery is
     * not the same as having shipped. Note what is NOT used here: the interim banner's
     * "Shipment Date", which is provisional and gets rescheduled by Amazon (§K).
     *
     * ╔══════════════════════════════════════════════════════════════════════════════╗
     * ║  ON NOON, THIS IS NULL UNTIL A PERSON TYPES THE DATE. THE TOOL DOES NOT      ║
     * ║  INVENT ONE. (M9 refinement of M8.)                                          ║
     * ╚══════════════════════════════════════════════════════════════════════════════╝
     *
     * A Noon workbook carries only Noon's own ESTIMATED delivery date, which is a plan.
     * M8 filled the gap with the upload day and marked it inferred - but an inferred date
     * still drives turnaround, still gets averaged into the benchmark, and still reads
     * like a fact on a screen. The day a file happened to be uploaded is not evidence of
     * anything about when goods moved, and a 15-day turnaround computed from it is worse
     * than no turnaround at all.
     *
     * So Noon gets no fallback. `estimatedDate()` carries Noon's estimate for display,
     * always labelled, and turnaround stays blank until the real date is entered on the
     * delivery. Amazon is unchanged: its final packing list states a real shipment date.
     */
    public function fulfilmentDate(): ?Carbon
    {
        if (! $this->has_final) {
            return null;
        }

        if ($this->delivered_on !== null) {
            return $this->delivered_on;
        }

        // Noon: no real date, and no substitute for one.
        if ($this->marketplace === Marketplace::Noon) {
            return null;
        }

        return $this->final_uploaded_at?->copy()->startOfDay();
    }

    /** True when the date above is the upload day standing in for a missing real date. */
    public function fulfilmentDateIsInferred(): bool
    {
        return $this->has_final
            && $this->delivered_on === null
            && $this->marketplace !== Marketplace::Noon;
    }

    /**
     * The channel's own ESTIMATE — a plan, never a fact.
     *
     * For Noon this is the "Estimated Delivery Date" off the PO tab; for Amazon it is the
     * packing list's planned shipment date. Shown as a placeholder wherever the real date
     * is missing, and never without ESTIMATED_LABEL beside it.
     */
    public function estimatedDate(): ?Carbon
    {
        return $this->planned_date;
    }

    /**
     * Is somebody being asked to type this delivery's real date?
     *
     * True for a shipped Noon delivery with no confirmed date. This is what puts it on a
     * to-do rather than letting it sit silently without turnaround.
     */
    public function awaitingDeliveryDate(): bool
    {
        return $this->has_final
            && $this->delivered_on === null
            && $this->marketplace === Marketplace::Noon;
    }

    /** The date a screen should print for this delivery. */
    public function shownDate(): ?Carbon
    {
        return $this->displayDate()[0];
    }

    /**
     * What that date actually IS, in three words — "estimated (not confirmed)", "entered
     * by hand", "planned". Never null-and-silent for a date that is not a fact.
     */
    public function shownDateNote(): ?string
    {
        return $this->displayDate()[1];
    }

    /** The date to show, and what it actually is. @return array{0: ?Carbon, 1: ?string} */
    public function displayDate(): array
    {
        if ($this->delivered_on !== null) {
            return [$this->delivered_on, $this->delivery_date_is_manual ? 'entered by hand' : null];
        }

        if ($this->awaitingDeliveryDate() && $this->planned_date !== null) {
            return [$this->planned_date, self::ESTIMATED_LABEL];
        }

        if ($this->fulfilmentDateIsInferred()) {
            return [$this->fulfilmentDate(), 'inferred from the upload day'];
        }

        return [$this->planned_date, $this->planned_date === null ? null : 'planned'];
    }

    /** Shipped Noon deliveries still waiting for somebody to confirm the date (M9). */
    public function scopeAwaitingDate(Builder $query): Builder
    {
        return $query->where('marketplace', Marketplace::Noon->value)
            ->where('has_final', true)
            ->whereNull('delivered_on');
    }

    /** Shortfall = interim - final, in units and money (§L). */
    public function computeShortfallUnits(): int
    {
        return max(0, $this->units_interim - $this->units_final);
    }

    public function computeShortfallValue(): float
    {
        return max(0, (float) $this->value_interim - (float) $this->value_final);
    }

    /** Deliveries still awaiting their final packing list. */
    public function scopeAwaitingFinal(Builder $query): Builder
    {
        return $query->where('has_interim', true)->where('has_final', false);
    }
}
