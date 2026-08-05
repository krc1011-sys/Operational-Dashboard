<?php

namespace App\Services\Margin;

use App\Models\Product;
use App\Models\ProductChannelEconomics;
use App\Models\PurchaseOrder;
use App\Support\Currency;

/**
 * True net margin (§S) — the sheet's P&L formulas, become the app's own calc logic.
 *
 * These are not invented. Each one was reverse-engineered from the real merged master
 * and checked against all 1,979 of its rows before being written down here; the
 * agreement counts are in NetMarginTest. The sheet's own answers are still imported and
 * kept beside ours, so any disagreement is a data-quality signal rather than a silent
 * overwrite (decision §10.9).
 *
 * The chain, per unit:
 *
 *     RSP ex VAT      = RSP inc VAT / (1 + VAT)                   VAT = 5% in the UAE
 *     Net receivable  = RSP ex VAT x (1 - platform fees %)        what the channel pays us
 *     COGS            = product cost + marketing + OPEX
 *                       + packaging + other misc                  what the unit costs us
 *     Profit          = net receivable - COGS
 *     Profit %        = profit / COGS                             markup on cost
 *     Margin %        = profit / net receivable                   share of revenue kept
 *
 * Profit % and margin % are different questions and the sheet asks both. Profit % is
 * "what did we make on the money we spent"; margin % is "what share of what we were paid
 * did we keep". Margin is always the smaller number. Reporting one as the other is the
 * easiest mistake to make here, so they are named apart and tested apart.
 *
 * WHY THE FEE BREAKDOWN IS NOT SUMMED. The file carries five fee columns (fulfilment,
 * referral, storage, category, other) and a headline "Platform Total Fees %". In the real
 * data the five are zero on 1,930 of 1,979 rows while the percentage is populated
 * throughout, and it is the percentage that reproduces the sheet's own net receivable
 * exactly. So the percentage drives the calculation and the breakdown is stored for when
 * it is filled in. If a row ever carries both, the importer raises an anomaly rather than
 * picking one quietly.
 */
class NetMarginEngine
{
    /** UAE VAT. Lives in config so KSA's 15% is a config change, like the currency map. */
    public static function vatRate(): float
    {
        return (float) config('operon.vat_rate', 0.05);
    }

    /**
     * Compute the derived figures for one product-channel row.
     *
     * Returns nulls rather than zeros where an input is missing: a product with no RSP
     * has no margin, and saying "0%" would read as "breaks even" when the truth is "we
     * do not know". The packaging materials in the real file are exactly this case -
     * they are things we buy, never sell, so they have a cost and no margin at all.
     *
     * @return array<string, float|null>
     */
    public static function compute(ProductChannelEconomics $e): array
    {
        $rspExVat = self::rspExVat($e);
        $cogs = self::cogs($e);

        $netReceivable = $rspExVat === null
            ? null
            : round($rspExVat * (1.0 - (float) ($e->platform_fees_pct ?? 0)), 4);

        $profit = ($netReceivable === null || $cogs === null)
            ? null
            : round($netReceivable - $cogs, 4);

        return [
            'rsp_ex_vat' => $rspExVat,
            'net_receivable' => $netReceivable,
            'cogs' => $cogs,
            'profit' => $profit,
            // Guard both denominators: a zero-cost or zero-revenue row has no percentage,
            // and dividing anyway would produce an infinity that then poisons every
            // average it lands in.
            'profit_pct' => ($profit === null || ! $cogs) ? null : round($profit / $cogs, 6),
            'margin_pct' => ($profit === null || ! $netReceivable) ? null : round($profit / $netReceivable, 6),
        ];
    }

    /**
     * RSP excluding VAT. Uses the file's own ex-VAT column when it has one, because that
     * is the number the rest of the sheet was built on; otherwise derives it.
     */
    public static function rspExVat(ProductChannelEconomics $e): ?float
    {
        if ($e->rsp_ex_vat !== null && (float) $e->rsp_ex_vat > 0) {
            return round((float) $e->rsp_ex_vat, 4);
        }

        $incVat = (float) ($e->rsp_with_vat ?? 0);

        return $incVat > 0 ? round($incVat / (1 + self::vatRate()), 4) : null;
    }

    /**
     * Everything a unit costs us: what we paid for it plus the spend that keeps it moving.
     *
     * Returns null only when there is no cost information at all. A missing marketing or
     * packaging figure counts as zero - that is a spend we did not make, unlike a missing
     * product cost, which is a fact we do not have.
     */
    public static function cogs(ProductChannelEconomics $e): ?float
    {
        $productCost = $e->product_cost ?? $e->product?->product_cost;

        if ($productCost === null) {
            return null;
        }

        return round(
            (float) $productCost
            + (float) ($e->marketing ?? 0)
            + (float) ($e->opex ?? 0)
            + (float) ($e->packaging ?? 0)
            + (float) ($e->other_misc ?? 0),
            4
        );
    }

    /** Recompute and persist one row. Returns the row for chaining. */
    public static function apply(ProductChannelEconomics $e): ProductChannelEconomics
    {
        $e->forceFill(self::compute($e));

        return $e;
    }

    /**
     * Recompute every product-channel row. Cheap enough to run after any import or edit -
     * the real catalog is under 2,000 rows - so the stored figures can never drift from
     * the inputs that produced them.
     */
    public static function recomputeAll(): int
    {
        $count = 0;

        ProductChannelEconomics::with('product:id,product_cost')
            ->chunkById(500, function ($rows) use (&$count) {
                foreach ($rows as $row) {
                    self::apply($row);

                    if ($row->isDirty()) {
                        $row->saveQuietly();
                    }

                    $count++;
                }
            });

        return $count;
    }

    /**
     * The per-unit cost stack used to cost a PO line: everything except platform fees.
     *
     * ⚠ ASSUMPTION, flagged for confirmation. Platform fees are deliberately excluded
     * here. A purchase order is wholesale - Amazon Retail and Noon Retail BUY from us at
     * the PO's own unit cost and resell themselves, so there is no referral or fulfilment
     * fee to pay on that transaction; those fees belong to the direct-to-customer picture
     * (DFS, §R) where we sell at RSP. Applying the catalog's 29.65% to a wholesale PO
     * would understate every PO's margin by roughly a third.
     *
     * If that reading is wrong the fix is one line, because this is the only place a PO's
     * cost stack is assembled.
     */
    public static function poCostPerUnit(ProductChannelEconomics $e): ?float
    {
        return self::cogs($e);
    }

    /**
     * Which channel's economics apply to a product on a given marketplace.
     *
     * A PO is a wholesale order, so it reads the retail channel for that marketplace:
     * Amazon Retail for Amazon, Noon Retail for Noon. Amazon DFS is not a PO channel at
     * all (§R), so it never answers here.
     */
    public static function economicsForPo(Product $product, string $marketplace): ?ProductChannelEconomics
    {
        $channel = match ($marketplace) {
            'amazon' => 'amazon_retail',
            'noon' => 'noon_retail',
            default => null,
        };

        if ($channel === null) {
            return null;
        }

        return $product->economics->firstWhere('channel', $channel);
    }

    /**
     * The net P&L for one purchase order (§S: "billed 10,000 -> net 1,000 = 10%").
     *
     * Revenue is what we actually billed, not what the catalog says we might have: units
     * shipped x the PO line's own unit cost, which is the price on the invoice. Cost is
     * the catalog's per-unit stack for that product on that channel. Using the real
     * billed figure matters, because a PO is often priced differently from the catalog's
     * standing invoice price, and the PO is the thing that happened.
     *
     * `coverage` is reported alongside, and reading it is not optional. A PO whose SKUs
     * are half missing from the catalog produces a profit figure covering half the order,
     * and that number is worse than useless if it is read as the whole. The money screens
     * at M7 must show coverage next to any total built from this.
     *
     * @return array<string, mixed>
     */
    public static function forPurchaseOrder(PurchaseOrder $po): array
    {
        $billed = 0.0;          // the whole PO, costable or not
        $costedRevenue = 0.0;   // only the part we can put a cost against
        $cost = 0.0;
        $costed = 0;
        $uncosted = 0;
        $uncostedUnits = 0;
        $currencies = [];

        foreach ($po->lines()->with('product.economics')->get() as $line) {
            $units = (int) $line->qty_shipped;

            if ($units <= 0) {
                continue;
            }

            $currencies[] = $line->currency;
            $lineRevenue = $units * (float) $line->unit_cost;
            $billed += $lineRevenue;

            $marketplace = $line->marketplace instanceof \BackedEnum
                ? $line->marketplace->value
                : (string) $line->marketplace;

            $economics = $line->product
                ? self::economicsForPo($line->product, $marketplace)
                : null;

            $perUnit = $economics ? self::poCostPerUnit($economics) : null;

            if ($perUnit === null) {
                $uncosted++;
                $uncostedUnits += $units;

                continue;
            }

            // Revenue and cost are added together or not at all. Counting a line's
            // revenue while missing its cost would report it as pure profit.
            $costedRevenue += $lineRevenue;
            $cost += $units * $perUnit;
            $costed++;
        }

        $profit = $costedRevenue - $cost;

        return [
            // What the whole PO billed, whether or not we can cost it.
            'billed' => round($billed, 2),
            // The part of that we can put a cost against, and the P&L on it.
            'revenue_costed' => round($costedRevenue, 2),
            'cost' => round($cost, 2),
            'profit' => $costed > 0 ? round($profit, 2) : null,
            'margin_pct' => ($costed > 0 && $costedRevenue > 0)
                ? round($profit / $costedRevenue * 100, 2)
                : null,
            'currency' => Currency::single($currencies),
            'coverage' => [
                'lines_costed' => $costed,
                'lines_uncosted' => $uncosted,
                'units_uncosted' => $uncostedUnits,
                'complete' => $uncosted === 0 && $costed > 0,
            ],
            // §S interim rule - anything built on this says so.
            'cost_basis' => config('operon.cost_basis', 'latest'),
        ];
    }
}
