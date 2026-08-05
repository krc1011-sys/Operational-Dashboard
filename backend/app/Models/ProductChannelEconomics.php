<?php

namespace App\Models;

use App\Enums\Channel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One product's unit economics on one channel (§S).
 *
 * The same physical product earns differently depending on who sells it: Amazon Retail
 * takes a different cut from Noon, at a different invoice price, with different marketing
 * behind it. So this is one row per (product, channel), and it is where every money
 * column from the master sheet lives.
 *
 * EVERYTHING ON THIS MODEL IS ADMIN-ONLY AND BEHIND THE PIN (§S). Unlike order value,
 * which is now open to every role, these are true costs and true profit. Nothing here
 * should ever reach a screen without both checks.
 */
class ProductChannelEconomics extends Model
{
    use HasFactory;

    protected $table = 'product_channel_economics';

    protected $guarded = ['id'];

    /**
     * What the master grid lets an Admin edit by hand.
     *
     * Deliberately the INPUTS only. The derived figures (net receivable, COGS, profit,
     * the percentages) are never editable, because they are the engine's answer - letting
     * someone type a profit that its inputs do not produce is how a spreadsheet starts
     * lying. Change an input and the engine recomputes on save.
     */
    public const EDITABLE = [
        'rsp_with_vat', 'rsp_ex_vat', 'invoice_cost_price',
        'fulfilment_fee', 'referral_fee', 'storage_fee', 'category_fee', 'other_fee',
        'platform_fees_pct',
        'product_cost', 'marketing', 'opex', 'packaging', 'other_misc',
        'currency',
    ];

    /** The engine's own output. Written only by NetMarginEngine. */
    public const DERIVED = [
        'net_receivable', 'cogs', 'profit', 'profit_pct', 'margin_pct',
    ];

    protected function casts(): array
    {
        return [
            'channel' => Channel::class,
            'extra' => 'array',
            'is_manual' => 'boolean',
            'rsp_with_vat' => 'decimal:4',
            'rsp_ex_vat' => 'decimal:4',
            'invoice_cost_price' => 'decimal:4',
            'fulfilment_fee' => 'decimal:4',
            'referral_fee' => 'decimal:4',
            'storage_fee' => 'decimal:4',
            'category_fee' => 'decimal:4',
            'other_fee' => 'decimal:4',
            'platform_fees_pct' => 'decimal:6',
            'product_cost' => 'decimal:4',
            'marketing' => 'decimal:4',
            'opex' => 'decimal:4',
            'packaging' => 'decimal:4',
            'other_misc' => 'decimal:4',
            'net_receivable_imported' => 'decimal:4',
            'cogs_imported' => 'decimal:4',
            'profit_imported' => 'decimal:4',
            'profit_pct_imported' => 'decimal:6',
            'margin_pct_imported' => 'decimal:6',
            'net_receivable' => 'decimal:4',
            'cogs' => 'decimal:4',
            'profit' => 'decimal:4',
            'profit_pct' => 'decimal:6',
            'margin_pct' => 'decimal:6',
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
     * How far our recomputed profit sits from the one the sheet shipped with.
     *
     * Null when the sheet had no figure to compare against. A non-trivial gap is worth
     * looking at: either the sheet's formula differs from ours, or its inputs changed
     * after its own numbers were last calculated.
     */
    public function profitDrift(): ?float
    {
        if ($this->profit === null || $this->profit_imported === null) {
            return null;
        }

        return round((float) $this->profit - (float) $this->profit_imported, 4);
    }

    /** The same comparison for COGS, which can disagree even when profit does not. */
    public function cogsDrift(): ?float
    {
        if ($this->cogs === null || $this->cogs_imported === null) {
            return null;
        }

        return round((float) $this->cogs - (float) $this->cogs_imported, 4);
    }

    /**
     * Does the sheet's own arithmetic agree with ours, to the fils?
     *
     * Checks COGS as well as profit, because the two can part company: a row with a real
     * product cost but no selling price has a knowable COGS and an unknowable profit, so
     * comparing profit alone would call it agreement when the costs differ.
     */
    public function agreesWithSheet(float $tolerance = 0.01): bool
    {
        foreach ([$this->profitDrift(), $this->cogsDrift()] as $drift) {
            if ($drift !== null && abs($drift) > $tolerance) {
                return false;
            }
        }

        return true;
    }
}
