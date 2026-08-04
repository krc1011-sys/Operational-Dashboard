<?php

namespace App\Models;

use App\Enums\CancellationResolution;
use App\Enums\Channel;
use App\Enums\Marketplace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A cancellation from the uploaded template - the ONLY thing that nets accepted units (§G).
 */
class Cancellation extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'marketplace' => Marketplace::class,
            'channel' => Channel::class,
            'resolution' => CancellationResolution::class,
            'is_unmatched' => 'boolean',
            'resolved_at' => 'datetime',
            'imported_at' => 'datetime',
        ];
    }

    public function poLine(): BelongsTo
    {
        return $this->belongsTo(PoLine::class);
    }

    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
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
     * Does the template's "Quantity Confirmed" disagree with the accepted quantity we
     * already hold? A mismatch is a warning, not a rejection (§G).
     */
    public function hasConfirmedQtyMismatch(): bool
    {
        if ($this->qty_confirmed === null || ! $this->poLine) {
            return false;
        }

        return $this->qty_confirmed !== $this->poLine->qty_accepted;
    }

    /**
     * Would honouring this cancellation claw back units already booked into a delivery
     * or already shipped? If so the system must stop and ask the user (§G).
     */
    public function requiresDecision(): bool
    {
        if (! $this->poLine) {
            return false;
        }

        $untouched = max(0, $this->poLine->qty_accepted - max(
            $this->poLine->qty_booked,
            $this->poLine->qty_shipped
        ));

        return $this->qty_cancelled > $untouched;
    }

    /**
     * Has a person answered this one? Their answer is final - the engine re-runs the
     * automatic rules only on rows nobody has decided and that have not netted yet
     * (the usual case being a cancellation that arrived before its PO).
     */
    public function isResolvedByHuman(): bool
    {
        return in_array($this->resolution, [
            CancellationResolution::PulledBack,
            CancellationResolution::DeliveredAnyway,
        ], true);
    }

    /** Still open to the automatic rules being re-run over it. */
    public function isPending(): bool
    {
        if ($this->isResolvedByHuman()) {
            return false;
        }

        return $this->resolution === CancellationResolution::NeedsDecision
            || ((int) $this->qty_honoured === 0 && (int) $this->qty_cancelled > 0);
    }

    /**
     * Units we have sent, or will send, despite the cancellation - the chargeback
     * exposure Amazon's email warns about (§G). Note that "pull it" can leave some
     * exposure behind: units already shipped cannot be un-shipped.
     */
    public function isChargebackExposure(): bool
    {
        return $this->qty_delivered_anyway > 0
            || $this->resolution === CancellationResolution::DeliveredAnyway;
    }

    public function scopeNeedsDecision(Builder $query): Builder
    {
        return $query->where('resolution', CancellationResolution::NeedsDecision->value);
    }

    /** Lines shipped despite a cancellation - our chargeback exposure (§G, §M). */
    public function scopeChargebackExposure(Builder $query): Builder
    {
        return $query->where(fn (Builder $q) => $q
            ->where('resolution', CancellationResolution::DeliveredAnyway->value)
            ->orWhere('qty_delivered_anyway', '>', 0));
    }
}
