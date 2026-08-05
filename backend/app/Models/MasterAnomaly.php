<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Something about the master catalog that a person needs to look at (§S cleanup).
 *
 * The rule this table exists to enforce: the import never quietly fixes anything it is
 * not certain about. A company product code that turns out to cover two different
 * products, or a Noon row holding an ASIN, has a right answer that only somebody who
 * knows the products can give. Guessing would push a wrong cost into every PO that SKU
 * appears on, and it would be invisible.
 *
 * So the import writes down what it found, in plain language, and carries on loading
 * everything else. Nothing is dropped and nothing is silently corrected.
 */
class MasterAnomaly extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    // --- What we look for -------------------------------------------------

    /** The merge tool's own REVIEW note, carried through verbatim. */
    public const KIND_FILE_FLAG = 'file_flag';

    /** One company product code, two genuinely different products. */
    public const KIND_CODE_COVERS_TWO_PRODUCTS = 'code_covers_two_products';

    /** A channel's identifier is the wrong shape - e.g. a Noon row holding an ASIN. */
    public const KIND_IDENTIFIER_SHAPE = 'identifier_shape';

    /** Two product codes claiming the same marketplace identifier. */
    public const KIND_IDENTIFIER_CONFLICT = 'identifier_conflict';

    /** The same product costs different amounts on different channels. */
    public const KIND_COST_DISAGREEMENT = 'cost_disagreement';

    /** A shared attribute (brand, category) differs between a product's channels. */
    public const KIND_ATTRIBUTE_DISAGREEMENT = 'attribute_disagreement';

    /** Our recomputed P&L disagrees with the figure the sheet shipped with (§10.9). */
    public const KIND_DERIVED_DISAGREEMENT = 'derived_disagreement';

    /** The fee breakdown and the headline fee percentage both carry values. */
    public const KIND_FEE_BASIS_AMBIGUOUS = 'fee_basis_ambiguous';

    /** An exact duplicate row, loaded once. */
    public const KIND_DUPLICATE_ROW = 'duplicate_row';

    /** Needs a human decision. */
    public const SEVERITY_REVIEW = 'review';

    /** Worth knowing, safe to leave. */
    public const SEVERITY_NOTE = 'note';

    protected function casts(): array
    {
        return [
            'details' => 'array',
            'resolved_at' => 'datetime',
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

    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNull('resolved_at');
    }

    public function scopeNeedsReview(Builder $query): Builder
    {
        return $query->open()->where('severity', self::SEVERITY_REVIEW);
    }

    /** Human labels for the kinds, for the review screen and its filters. */
    public static function kindLabels(): array
    {
        return [
            self::KIND_FILE_FLAG => 'Flagged in the file',
            self::KIND_CODE_COVERS_TWO_PRODUCTS => 'One code, two products',
            self::KIND_IDENTIFIER_SHAPE => 'Identifier looks wrong for its channel',
            self::KIND_IDENTIFIER_CONFLICT => 'Two products claim one identifier',
            self::KIND_COST_DISAGREEMENT => 'Cost differs between channels',
            self::KIND_ATTRIBUTE_DISAGREEMENT => 'Details differ between channels',
            self::KIND_DERIVED_DISAGREEMENT => "Our figures differ from the sheet's",
            self::KIND_FEE_BASIS_AMBIGUOUS => 'Fees given two ways',
            self::KIND_DUPLICATE_ROW => 'Duplicate row',
        ];
    }
}
