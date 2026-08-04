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
 * One item row from a packing/picking list (§K).
 */
class ShipmentLine extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    /**
     * Rows whose title cell is literally this are per-carton subtotals for the packer.
     * They must be skipped at parse time or units double-count (§K).
     */
    public const CARTON_TOTAL_MARKER = 'carton total';

    protected function casts(): array
    {
        return [
            'marketplace' => Marketplace::class,
            'channel' => Channel::class,
            'stage' => Stage::class,
            'is_unmatched' => 'boolean',
            'imported_at' => 'datetime',
            'unit_cost' => 'decimal:4',
            'line_value' => 'decimal:4',
        ];
    }

    public function delivery(): BelongsTo
    {
        return $this->belongsTo(Delivery::class);
    }

    public function poLine(): BelongsTo
    {
        return $this->belongsTo(PoLine::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function sourceFile(): BelongsTo
    {
        return $this->belongsTo(SourceFile::class);
    }

    /** Is this title cell a carton subtotal rather than a real item? (§K) */
    public static function isCartonTotalRow(?string $title): bool
    {
        return strtolower(trim((string) $title)) === self::CARTON_TOTAL_MARKER;
    }

    public function scopeStage(Builder $query, Stage $stage): Builder
    {
        return $query->where('stage', $stage->value);
    }

    /** Lines still waiting for their PO to be uploaded (§K auto-reconcile). */
    public function scopeUnmatched(Builder $query): Builder
    {
        return $query->where('is_unmatched', true);
    }
}
