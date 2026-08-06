<?php

namespace App\Services\Margin;

use App\Models\Product;
use App\Models\ProductChannelEconomics;
use App\Models\PurchaseOrder;
use App\Support\Currency;

/**
 * True net margin (§S) — the vendor P&L, as the master sheet's own columns describe it.
 *
 * WE ARE A VENDOR, NOT A SELLER-CENTRAL SELLER. Amazon Vendor Central, Amazon DFS and
 * Noon Retail all BUY from us and resell themselves. What the marketplace keeps is two
 * margins, and nothing else:
 *
 *     front margin - taken off the retail price to reach the invoice / PO value
 *     back  margin - taken off the invoice to reach what we actually bank
 *
 * The five Seller-Central fee columns the sheet carries - fulfilment, referral,
 * warehouse/storage, category and other fees - DO NOT APPLY to us. They are stored
 * because the file has them, and they are never deducted by anything here.
 *
 * The chain, per unit:
 *
 *     RSP ex VAT      = RSP inc VAT / (1 + VAT)              VAT = 5% in the UAE
 *     Invoice value   = RSP ex VAT x invoice_pct_of_rsp      0.9019 Amazon · 0.98 Noon
 *     Net receivable  = Invoice    x net_pct_of_invoice      0.78 standard
 *     COGS            = product cost + marketing + OPEX + packaging + other misc
 *     Profit          = net receivable - COGS
 *     Profit %        = profit / COGS                        markup on what we spent
 *     Margin %        = profit / net receivable              share of receipts we keep
 *
 * BOTH RATES ARE PER ROW, NOT PER CHANNEL. They come from the file where it states them
 * and fall back to the channel defaults in config/operon.php otherwise. This is not
 * over-engineering: the real file proves they vary. The standard rates reconcile with the
 * sheet's own invoice and net-receivable columns exactly - Amazon Retail 749 of 749 on
 * both steps, Noon 533 of 533 on the invoice step - but 151 Noon rows keep 0.80 of the
 * invoice instead of 0.78, and every one of them is Category = FnB. Food carries a
 * different back margin, and hardcoding 22% would have quietly overstated the
 * marketplace's cut on all 151.
 *
 * Profit % and margin % are different questions and the sheet asks both. Profit % is
 * "what did we make on the money we spent"; margin % is "what share of what we were paid
 * did we keep". Margin is always the smaller number. Reporting one as the other is the
 * easiest mistake to make here, so they are named apart and tested apart.
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

        // Front margin: retail price -> invoice / PO value.
        $invoice = $rspExVat === null
            ? null
            : round($rspExVat * self::invoicePctOfRsp($e), 4);

        // Back margin: invoice -> what we actually bank.
        $netReceivable = $invoice === null
            ? null
            : round($invoice * self::netPctOfInvoice($e), 4);

        $profit = ($netReceivable === null || $cogs === null)
            ? null
            : round($netReceivable - $cogs, 4);

        return [
            'rsp_ex_vat' => $rspExVat,
            'invoice_value' => $invoice,
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
     * The front-margin rate: what the invoice is worth as a share of the retail price.
     *
     * The row's own rate wins, because it is what the file actually says about this
     * product. The channel default only covers a row that has never been through an
     * import - a product typed straight into the grid.
     */
    public static function invoicePctOfRsp(ProductChannelEconomics $e): float
    {
        if ($e->invoice_pct_of_rsp !== null && (float) $e->invoice_pct_of_rsp > 0) {
            return (float) $e->invoice_pct_of_rsp;
        }

        return self::defaultRates($e->channel?->value)['invoice_pct_of_rsp'];
    }

    /** The back-margin rate: what we bank as a share of the invoice. */
    public static function netPctOfInvoice(ProductChannelEconomics $e): float
    {
        if ($e->net_pct_of_invoice !== null && (float) $e->net_pct_of_invoice > 0) {
            return (float) $e->net_pct_of_invoice;
        }

        return self::defaultRates($e->channel?->value)['net_pct_of_invoice'];
    }

    /** The channel's standard rates, for a row the file has not spoken about. */
    public static function defaultRates(?string $channel): array
    {
        $rates = config('operon.margin.'.$channel);

        // An unknown channel keeps the whole invoice rather than inventing a deduction.
        // A margin that is too good is noticed; one quietly shaved is not.
        return [
            'invoice_pct_of_rsp' => (float) ($rates['invoice_pct_of_rsp'] ?? 1.0),
            'net_pct_of_invoice' => (float) ($rates['net_pct_of_invoice'] ?? 1.0),
        ];
    }

    /**
     * What the marketplace keeps in total, as a share of the retail price.
     * Amazon 29.65%, Noon 23.56%, Noon food 21.60%. For display and cross-checking
     * against the sheet's own "Platform Total Fees %" column.
     */
    public static function marketplaceTakePct(ProductChannelEconomics $e): float
    {
        return round(1 - (self::invoicePctOfRsp($e) * self::netPctOfInvoice($e)), 6);
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
        $stack = self::costStack($e);

        return $stack === null ? null : round(array_sum($stack), 4);
    }

    /**
     * The cost stack, itemised — the same figures COGS adds up, kept apart (§Profitability).
     *
     * M7's P&L wants "revenue − product cost − marketing − OPEX − packaging = net", with
     * each deduction on its own line. Those lines are THESE keys, in this order, and COGS
     * is defined as their sum rather than computed alongside them: a statement whose lines
     * do not add up to its total is worse than one with no lines at all.
     *
     * Anything reading zero here is a spend the master sheet has not recorded yet, not a
     * spend of zero, and the P&L labels it that way (`ProfitAndLoss::UNTIL_DATA_ADDED`).
     * When the figure arrives in the sheet it appears on the line that is already there,
     * with no code change.
     *
     * @return array<string, float>|null  null when there is no product cost at all
     */
    public static function costStack(ProductChannelEconomics $e): ?array
    {
        $productCost = $e->product_cost ?? $e->product?->product_cost;

        // A missing PRODUCT COST is a fact we do not have, so there is no cost stack. A
        // missing marketing or packaging figure is a spend we did not make: zero.
        if ($productCost === null) {
            return null;
        }

        return [
            'product_cost' => (float) $productCost,
            'marketing' => (float) ($e->marketing ?? 0),
            'opex' => (float) ($e->opex ?? 0),
            'packaging' => (float) ($e->packaging ?? 0),
            'other_misc' => (float) ($e->other_misc ?? 0),
        ];
    }

    /** The cost-stack lines, in P&L order. */
    public static function costComponents(): array
    {
        return ['product_cost', 'marketing', 'opex', 'packaging', 'other_misc'];
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
     * The per-unit cost stack for a PO line: what the unit costs US.
     *
     * The marketplace's cut is NOT in here, and must not be - it comes off the revenue
     * side, as the back margin on the invoice. Subtracting it here as well would charge
     * it twice.
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
     * A PO IS THE INVOICE. Its unit cost is the price we bill the marketplace, which is
     * the front margin already applied - so what remains to deduct on the revenue side is
     * the BACK margin, the marketplace's cut of the invoice. We bill 100 and bank 78.
     *
     * That is the correction that matters most here. Treating the billed figure as money
     * received overstates every PO's margin by the whole back margin - on the real
     * 8-delivery PO, by about 49,000.
     *
     * Revenue is what we actually billed, not what the catalog says we might have: the
     * PO's own unit cost, because the PO is the thing that happened.
     *
     * `coverage` is reported alongside, and reading it is not optional. A PO whose SKUs
     * are half missing from the catalog produces a profit covering half the order, and
     * that number is worse than useless if it is read as the whole. The money screens at
     * M7 must show coverage next to any total built from this.
     *
     * @return array<string, mixed>
     */
    public static function forPurchaseOrder(PurchaseOrder $po): array
    {
        $billed = 0.0;          // the whole PO invoice, costable or not
        $costedInvoice = 0.0;   // the part of it we can put a cost against
        $netReceivable = 0.0;   // that part, after the marketplace's back margin
        $cost = 0.0;
        $costed = 0;
        $uncosted = 0;
        $uncostedUnits = 0;
        $costedUnits = 0;
        $currencies = [];
        $backRates = [];

        // The itemised deductions, accumulated beside the total they make up rather than
        // recomputed afterwards - which is the only way the P&L's lines can be trusted to
        // add up to its bottom line.
        $breakdown = array_fill_keys(self::costComponents(), 0.0);

        foreach ($po->lines()->with('product.economics')->get() as $line) {
            $units = (int) $line->qty_shipped;

            if ($units <= 0) {
                continue;
            }

            $currencies[] = $line->currency;
            $lineInvoice = $units * (float) $line->unit_cost;
            $billed += $lineInvoice;

            $marketplace = $line->marketplace instanceof \BackedEnum
                ? $line->marketplace->value
                : (string) $line->marketplace;

            $economics = $line->product
                ? self::economicsForPo($line->product, $marketplace)
                : null;

            $stack = $economics ? self::costStack($economics) : null;
            $perUnit = $stack === null ? null : round(array_sum($stack), 4);

            if ($perUnit === null) {
                $uncosted++;
                $uncostedUnits += $units;

                continue;
            }

            // Revenue and cost are added together or not at all. Counting a line's
            // revenue while missing its cost would report it as pure profit.
            $backRate = self::netPctOfInvoice($economics);
            $backRates[] = $backRate;

            $costedInvoice += $lineInvoice;
            $netReceivable += $lineInvoice * $backRate;
            $costedUnits += $units;
            $costed++;

            foreach ($stack as $component => $perUnitAmount) {
                $breakdown[$component] += $units * $perUnitAmount;
            }
        }

        // Round the itemised lines first and total THEM, so the deductions a person adds
        // up on the P&L make the total printed underneath, to the fils. Totalling
        // separately and rounding afterwards can leave the two a fils apart, and a
        // statement that does not add up is not believed - correctly.
        $breakdown = array_map(fn (float $v) => round($v, 2), $breakdown);
        $cost = array_sum($breakdown);

        $profit = $netReceivable - $cost;

        return [
            // What the whole PO billed, whether or not we can cost it.
            'billed' => round($billed, 2),
            // The part of that we can cost, what we bank on it, and the P&L.
            'invoice_costed' => round($costedInvoice, 2),
            'net_receivable' => $costed > 0 ? round($netReceivable, 2) : null,
            'cost' => round($cost, 2),
            // The same total, itemised, for the P&L statement (§Profitability).
            'cost_breakdown' => $breakdown,
            'units_costed' => $costedUnits,
            'profit' => $costed > 0 ? round($profit, 2) : null,
            // Margin is against what we BANK, not what we billed - the honest denominator.
            'margin_pct' => ($costed > 0 && $netReceivable > 0)
                ? round($profit / $netReceivable * 100, 2)
                : null,
            // What the marketplace kept off the invoice, shown rather than buried.
            'back_margin_deducted' => round($costedInvoice - $netReceivable, 2),
            'back_margin_pct' => $backRates === []
                ? null
                : round((1 - array_sum($backRates) / count($backRates)) * 100, 2),
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
