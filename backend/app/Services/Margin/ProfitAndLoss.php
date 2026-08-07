<?php

namespace App\Services\Margin;

use App\Models\PurchaseOrder;

/**
 * The PO-level P&L statement (blueprint §Profitability, M7).
 *
 * "billed 10,000 → net 1,000 = 10%" — the blueprint's own example, and the shape this
 * builds: one column of figures read top to bottom, where every line either adds to or
 * takes from the one above it, and the bottom line is the profit.
 *
 *     Invoiced (billed)                223,511.20     what the PO is worth
 *     less the part we cannot cost        −514.80     revenue with no cost against it
 *     = costable invoice                222,996.40
 *     less the marketplace's cut       −49,059.21     the back margin: we bill 100, bank 78
 *     = NET RECEIVABLE                  173,937.19     what we actually bank
 *     less product cost                −xxx,xxx.xx
 *     less marketing                   −xxx,xxx.xx
 *     less OPEX                        −xxx,xxx.xx
 *     less packaging                    −x,xxx.xx
 *     less other                        −x,xxx.xx
 *     = NET PROFIT                      31,537.69     margin 18.13%
 *
 * NOTHING HERE CALCULATES. Every figure is NetMarginEngine's, taken as given: the engine
 * is the single P&L authority (§S), and a view that re-derived any of it would eventually
 * disagree with the master grid showing the same product. This class chooses the order,
 * the words and which line carries a caveat — that is all it does.
 *
 * THE ZERO LINES ARE DELIBERATE. Marketing, OPEX and packaging appear whether or not the
 * master sheet carries a figure for them. When it does not, the line reads 0 and is
 * tagged "until data added" rather than being hidden: a P&L missing a cost line reads as
 * a P&L with no such cost, which is a different and much more flattering claim. The line
 * is already wired to the engine, so the day the figure lands in the sheet it appears
 * here with no code change.
 */
class ProfitAndLoss
{
    /** The tag on a cost line the data has not reached yet. */
    public const UNTIL_DATA_ADDED = 'until data added';

    /** Line kinds, for the view: how each row is weighted and coloured. */
    public const REVENUE = 'revenue';

    public const DEDUCTION = 'deduction';

    public const SUBTOTAL = 'subtotal';

    public const RESULT = 'result';

    /** What each cost-stack key is called on screen, in P&L order. */
    public const COST_LABELS = [
        'product_cost' => 'Product cost',
        'marketing' => 'Marketing',
        'opex' => 'OPEX',
        'packaging' => 'Packaging',
        'other_misc' => 'Other',
    ];

    /**
     * The statement for one PO.
     *
     * @return array<string, mixed>
     */
    public static function forPurchaseOrder(PurchaseOrder $po): array
    {
        return self::fromResult(NetMarginEngine::forPurchaseOrder($po), $po);
    }

    /**
     * The statement for a result the engine has already produced.
     *
     * Split out so a screen that has computed a PO's figures once — the Profitability
     * list computes one per PO — can shape them without asking the engine twice.
     *
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    public static function fromResult(array $result, ?PurchaseOrder $po = null): array
    {
        $costable = $result['coverage']['lines_costed'] > 0;

        return [
            'po' => $po,
            'currency' => $result['currency'],
            'lines' => $costable ? self::lines($result) : [],
            'costable' => $costable,
            // Kept alongside the lines because the tiles and the export want the raw
            // figures, and re-reading them out of the formatted lines would be silly.
            'billed' => $result['billed'],
            'invoice_costed' => $result['invoice_costed'],
            'net_receivable' => $result['net_receivable'],
            'back_margin_deducted' => $result['back_margin_deducted'],
            'cost' => $result['cost'],
            'cost_breakdown' => $result['cost_breakdown'],
            'profit' => $result['profit'],
            'margin_pct' => $result['margin_pct'],
            'back_margin_pct' => $result['back_margin_pct'],
            'coverage' => $result['coverage'],
            'cost_basis' => $result['cost_basis'],
            'units_costed' => $result['units_costed'] ?? 0,
            // Which cost lines the sheet has no figures for yet — the caveat the screen
            // states once at the foot rather than five times in the table.
            'pending' => self::pendingComponents($result),
        ];
    }

    /**
     * The statement rows, in reading order.
     *
     * @param  array<string, mixed>  $result
     * @return array<int, array<string, mixed>>
     */
    private static function lines(array $result): array
    {
        $uncostedInvoice = round($result['billed'] - $result['invoice_costed'], 2);

        // Every line carries the same keys, present or null. A view reaching for a key
        // some lines happen not to have is a warning waiting for the one PO that takes
        // the other branch, and this method has two branches.
        $defaults = ['label' => '', 'note' => null, 'amount' => null,
            'kind' => self::DEDUCTION, 'pending' => false];

        $lines = [[
            'label' => 'Invoiced to the marketplace',
            'note' => 'Units shipped × the PO\'s own unit price — a PO is the invoice',
            'amount' => $result['billed'],
            'kind' => self::REVENUE,
        ]];

        // Only shown when there IS uncosted revenue. A line reading zero here would be
        // noise; a missing line when it is not zero would be a hidden hole in the answer.
        if (abs($uncostedInvoice) >= 0.01) {
            $lines[] = [
                'label' => 'Less the part we cannot cost',
                'note' => $result['coverage']['lines_uncosted'].' line(s), '
                    .number_format($result['coverage']['units_uncosted']).' units, not in the catalog — '
                    .'left out of both sides so they cannot read as pure profit',
                'amount' => -$uncostedInvoice,
                'kind' => self::DEDUCTION,
            ];

            $lines[] = [
                'label' => 'Costable invoice',
                'note' => null,
                'amount' => $result['invoice_costed'],
                'kind' => self::SUBTOTAL,
            ];
        }

        $lines[] = [
            'label' => 'Less the marketplace\'s margin',
            'note' => $result['back_margin_pct'] === null
                ? 'What the channel keeps off the invoice'
                : 'The back margin — '.$result['back_margin_pct'].'% of the invoice. We bill 100 and bank '
                    .round(100 - $result['back_margin_pct'], 2).'.',
            'amount' => -$result['back_margin_deducted'],
            'kind' => self::DEDUCTION,
        ];

        $lines[] = [
            'label' => 'Net receivable',
            'note' => 'What we actually bank on this PO',
            'amount' => $result['net_receivable'],
            'kind' => self::SUBTOTAL,
        ];

        foreach (self::COST_LABELS as $key => $label) {
            $amount = (float) ($result['cost_breakdown'][$key] ?? 0);

            $lines[] = [
                'label' => 'Less '.mb_strtolower($label),
                'note' => self::costNote($key, $amount, $result),
                'amount' => -$amount,
                'kind' => self::DEDUCTION,
                'pending' => $amount == 0.0,
            ];
        }

        $lines[] = [
            'label' => 'Net profit',
            'note' => $result['margin_pct'] === null
                ? null
                : $result['margin_pct'].'% of what we bank',
            'amount' => $result['profit'],
            'kind' => self::RESULT,
        ];

        return array_map(fn (array $line) => $line + $defaults, $lines);
    }

    /** The one-line explanation under a cost line. */
    private static function costNote(string $key, float $amount, array $result): ?string
    {
        if ($amount == 0.0) {
            return 'No figure in the master sheet yet — this line is wired up and reads 0 '
                .self::UNTIL_DATA_ADDED.'.';
        }

        return match ($key) {
            'product_cost' => 'At the '.$result['cost_basis'].' supplier price (§S interim rule)',
            default => 'Per unit from the master sheet, × '.number_format($result['units_costed'] ?? 0).' units',
        };
    }

    /**
     * Which cost lines are still empty.
     *
     * @return array<int, string>
     */
    private static function pendingComponents(array $result): array
    {
        $pending = [];

        foreach (self::COST_LABELS as $key => $label) {
            if ((float) ($result['cost_breakdown'][$key] ?? 0) == 0.0) {
                $pending[] = $label;
            }
        }

        return $pending;
    }
}
