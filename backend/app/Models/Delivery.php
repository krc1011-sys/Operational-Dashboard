<?php

namespace App\Models;

use App\Enums\Channel;
use App\Enums\Marketplace;
use App\Enums\Stage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
