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

    /**
     * The date this delivery actually went out - what turnaround is measured against (§L).
     *
     * Null until a final packing list exists, because being booked into a delivery is
     * not the same as having shipped. Note what is NOT used here: the interim banner's
     * "Shipment Date", which is provisional and gets rescheduled by Amazon (§K).
     *
     * The ladder is: the real delivery date (the final's date, or the one typed in for
     * Noon) → failing that, the day the final was uploaded. The second is an
     * approximation and `fulfilmentDateIsInferred()` says so, so a screen can mark it.
     */
    public function fulfilmentDate(): ?Carbon
    {
        if (! $this->has_final) {
            return null;
        }

        return $this->delivered_on ?? $this->final_uploaded_at?->copy()->startOfDay();
    }

    /** True when the date above is the upload day standing in for a missing real date. */
    public function fulfilmentDateIsInferred(): bool
    {
        return $this->has_final && $this->delivered_on === null;
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
