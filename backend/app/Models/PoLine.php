<?php

namespace App\Models;

use App\Enums\Channel;
use App\Enums\Marketplace;
use App\Enums\Stage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One PO x SKU line - the spine of the system (§C, §F).
 *
 * The cached qty_* / fill_rate_pct / line_state columns are written by the M4
 * reconciliation engine. The helpers here define what they MEAN, so the engine and the
 * screens cannot drift apart.
 */
class PoLine extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    /** Line states from §F. */
    public const STATE_CANCELLED = 'cancelled';
    public const STATE_NOT_BOOKED = 'not_booked';
    public const STATE_SCHEDULED = 'scheduled';
    public const STATE_DISPATCHED = 'dispatched';

    protected function casts(): array
    {
        return [
            'marketplace' => Marketplace::class,
            'channel' => Channel::class,
            'window_start' => 'date',
            'window_end' => 'date',
            'expected_date' => 'date',
            'imported_at' => 'datetime',
            'has_chargeback_flag' => 'boolean',
            'unit_cost' => 'decimal:4',
            'fill_rate_pct' => 'decimal:4',
        ];
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function sourceFile(): BelongsTo
    {
        return $this->belongsTo(SourceFile::class);
    }

    /** Packing lines that matched this PO line, both stages. */
    public function shipmentLines()
    {
        return $this->hasMany(ShipmentLine::class);
    }

    public function cancellation()
    {
        return $this->hasOne(Cancellation::class);
    }

    // --- Definitions the reconciliation engine and the screens share ---------

    /** Net accepted = accepted - honoured cancellations (§E). */
    public function computeNetAccepted(): int
    {
        return max(0, $this->qty_accepted - $this->qty_cancelled_honoured);
    }

    /** Not-booked = net accepted - booked - cancelled (§F). Never negative on screen. */
    public function computeNotBooked(): int
    {
        return max(0, $this->computeNetAccepted() - $this->qty_booked);
    }

    /** Fill rate (final) = shipped / net accepted (§E). Null when there is nothing to fill. */
    public function computeFillRate(): ?float
    {
        $net = $this->computeNetAccepted();

        return $net > 0 ? round($this->qty_shipped / $net * 100, 4) : null;
    }

    /** The §F state label derived from the numbers. */
    public function computeState(): string
    {
        if ($this->computeNetAccepted() === 0 && $this->qty_cancelled_honoured > 0) {
            return self::STATE_CANCELLED;
        }

        if ($this->qty_shipped > 0) {
            return self::STATE_DISPATCHED;
        }

        return $this->qty_booked > 0 ? self::STATE_SCHEDULED : self::STATE_NOT_BOOKED;
    }

    /** Units booked or shipped, straight from the packing lines. */
    public function sumStage(Stage $stage): int
    {
        return (int) ShipmentLine::query()
            ->where('marketplace', $this->marketplace->value)
            ->where('po_number', $this->po_number)
            ->where('sku_id', $this->sku_id)
            ->where('stage', $stage->value)
            ->sum('qty');
    }

    // --- Scopes for the self-serve filters (§M) ------------------------------

    public function scopeChannel(Builder $query, Channel|string|array $channel): Builder
    {
        $values = collect(is_array($channel) ? $channel : [$channel])
            ->map(fn ($c) => $c instanceof Channel ? $c->value : $c)
            ->all();

        return $query->whereIn('channel', $values);
    }

    /** Powers the §M bulk-ASIN/NIN paste-or-upload filter. */
    public function scopeSkuIn(Builder $query, array $skuIds): Builder
    {
        return $query->whereIn($query->qualifyColumn('sku_id'), array_map('trim', $skuIds));
    }

    /**
     * Free-text search across ASIN/NIN, barcode and title (§B).
     *
     * Columns are qualified because the reporting screens join this table to `products`
     * for the brand/category grouping, and both tables have a `barcode`.
     */
    public function scopeSearch(Builder $query, string $term): Builder
    {
        $term = trim($term);

        return $query->where(fn (Builder $q) => $q
            ->where($q->qualifyColumn('sku_id'), 'like', "%{$term}%")
            ->orWhere($q->qualifyColumn('barcode'), 'like', "%{$term}%")
            ->orWhere($q->qualifyColumn('title'), 'like', "%{$term}%")
            ->orWhere($q->qualifyColumn('po_number'), 'like', "%{$term}%"));
    }
}
