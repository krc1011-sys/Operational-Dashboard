<?php

namespace App\Http\Controllers;

use App\Models\PurchaseOrder;
use App\Services\Margin\NetMarginEngine;
use App\Services\Margin\ProfitAndLoss;
use App\Services\Margin\SkuMargin;
use App\Services\Reporting\CsvExport;
use App\Services\Reporting\FilterSet;
use App\Support\Currency;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Profitability — the money views (blueprint §Profitability, M7).
 *
 * Two questions, one section, because they are the same question asked of two different
 * things and the answer to one leads to the other:
 *
 *   PO-LEVEL   "billed 10,000 → net 1,000 = 10%" — did this order make money?
 *   SKU-LEVEL  "is this product profitable?" — with the Amazon / Noon / Both selector.
 *
 * Everything on both screens is `view-margin` AND the PIN. That is the whole route group,
 * because unlike the shared screens there is nothing here that is not money — someone
 * without the permission has no reason to be on this URL at all.
 *
 * NO ARITHMETIC LIVES IN THIS CONTROLLER. NetMarginEngine is the single P&L authority
 * (§S) and M7 is views over M6's answers; ProfitAndLoss shapes a PO's figures into a
 * statement, SkuMargin blends channels. If a number here ever disagreed with the same
 * number on the master grid, the app would be worth less than the spreadsheet it replaces.
 */
class MoneyController extends Controller
{
    public const VIEW_PO = 'po';
    public const VIEW_SKU = 'sku';

    public function index(Request $request): View|RedirectResponse|StreamedResponse
    {
        $filters = FilterSet::fromRequest($request);
        $view = $request->query('view') === self::VIEW_SKU ? self::VIEW_SKU : self::VIEW_PO;

        if ($request->isMethod('post')) {
            return redirect()->route('money.index', $filters->query(['view' => $view]));
        }

        return $view === self::VIEW_SKU
            ? $this->skuView($request, $filters)
            : $this->poView($request, $filters);
    }

    /** One PO's full statement (§Profitability: PO-level net P&L). */
    public function show(Request $request, string $poNumber): View
    {
        $order = PurchaseOrder::where('po_number', $poNumber)->firstOrFail();

        return view('money.po', [
            'order' => $order,
            'pnl' => ProfitAndLoss::forPurchaseOrder($order),
            'benchmark' => (int) config('operon.benchmarks.turnaround_days'),
        ]);
    }

    // --- PO-level ---------------------------------------------------------

    private function poView(Request $request, FilterSet $filters): View|StreamedResponse
    {
        $query = $filters->applyToPurchaseOrders(PurchaseOrder::query())
            ->orderByDesc('order_date')->orderBy('po_number');

        if ($request->query('export') === 'csv') {
            return $this->exportPoPnl($filters, $query);
        }

        $orders = $query->paginate(50)->withQueryString();

        // One statement per PO on this page. The engine is asked once per order and its
        // answer is reused for the row, the totals and the export.
        $statements = collect($orders->items())
            ->map(fn (PurchaseOrder $po) => ProfitAndLoss::fromResult(
                NetMarginEngine::forPurchaseOrder($po), $po
            ));

        return view('money.index', [
            'view' => self::VIEW_PO,
            'filters' => $filters,
            'orders' => $orders,
            'statements' => $statements,
            'totals' => $this->portfolioTotals($statements),
            'costBasis' => config('operon.cost_basis', 'latest'),
        ]);
    }

    /**
     * The whole page's P&L, added up.
     *
     * Margin is Σ profit ÷ Σ net receivable — the SAME revenue weighting the SKU blend
     * uses, and for the same reason: averaging the POs' percentages would let a tiny
     * order swing the headline as hard as a huge one.
     */
    private function portfolioTotals($statements): array
    {
        $costable = $statements->filter(fn ($s) => $s['costable']);

        $net = (float) $costable->sum('net_receivable');
        $profit = (float) $costable->sum('profit');

        return [
            'pos' => $statements->count(),
            'pos_costable' => $costable->count(),
            'billed' => round((float) $statements->sum('billed'), 2),
            'net_receivable' => round($net, 2),
            'cost' => round((float) $costable->sum('cost'), 2),
            'profit' => round($profit, 2),
            'margin_pct' => $net > 0 ? round($profit / $net * 100, 2) : null,
            'currency' => Currency::single($statements->pluck('currency')),
            'incomplete' => $statements->filter(fn ($s) => ! ($s['coverage']['complete'] ?? false))->count(),
        ];
    }

    // --- SKU-level --------------------------------------------------------

    private function skuView(Request $request, FilterSet $filters): View|StreamedResponse
    {
        $selector = SkuMargin::selector($request->query('channel_view'));

        // Everything matching is blended, because you cannot know which SKUs are the
        // worst without costing all of them. The export takes the lot; the table takes
        // the first screenful and says so.
        $all = SkuMargin::rows($selector, $filters, null);

        if ($request->query('export') === 'csv') {
            return $this->exportSkuMargin($filters, $selector, $all);
        }

        return view('money.index', [
            'view' => self::VIEW_SKU,
            'filters' => $filters,
            'selector' => $selector,
            'rows' => $all->take(SkuMargin::TABLE_LIMIT),
            'shown' => min($all->count(), SkuMargin::TABLE_LIMIT),
            // The summary counts EVERY matching SKU, not only the ones drawn - a headline
            // that silently described the first 200 would be the worst of both.
            'summary' => $this->skuSummary($all),
            'costBasis' => config('operon.cost_basis', 'latest'),
        ]);
    }

    /**
     * How the SKUs in view divide up.
     *
     * The blended headline is revenue-weighted across every SKU exactly as each SKU's own
     * blend is across its channels — one rule, applied at both levels.
     */
    private function skuSummary($rows): array
    {
        /*
         * Bundle components are out of every ranked figure here (M8).
         *
         * They are never sold on their own, so the selling price their margin is computed
         * against was never charged - including them in the headline would let a phantom
         * loss move a real number. Their COST is untouched and still on screen; it is the
         * verdict that is withheld, not the data.
         */
        $rankable = $rows->reject(fn ($r) => $r['bundle_component']);

        $revenue = (float) $rankable->sum(fn ($r) => $r['blend']['revenue_total']);
        $profit = (float) $rankable->sum(fn ($r) => $r['blend']['profit_total']);

        return [
            'skus' => $rows->count(),
            'rankable' => $rankable->count(),
            'profitable' => $rankable->filter(fn ($r) => $r['profitable'] === true)->count(),
            'losing' => $rankable->filter(fn ($r) => $r['profitable'] === false)->count(),
            'unknown' => $rankable->filter(fn ($r) => $r['profitable'] === null)->count(),
            'bundle_components' => $rows->count() - $rankable->count(),
            'margin_pct' => $revenue > 0 ? round($profit / $revenue * 100, 2) : null,
            'shipped_weighted' => $rows->filter(
                fn ($r) => $r['blend']['weight_basis'] === SkuMargin::BASIS_SHIPPED
            )->count(),
            'currency' => Currency::single($rows->pluck('currency')),
        ];
    }

    // --- Exports ----------------------------------------------------------

    private function exportPoPnl(FilterSet $filters, $query): StreamedResponse
    {
        $costLabels = array_values(ProfitAndLoss::COST_LABELS);

        $headers = array_merge(
            ['PO', 'Order date', 'FC', 'Currency', 'Invoiced', 'Costable invoice',
                "Marketplace's margin", 'Net receivable'],
            $costLabels,
            ['Total cost', 'Net profit', 'Margin %', 'Lines costed', 'Lines uncosted', 'Cost basis']
        );

        $rows = function () use ($query) {
            foreach ($query->cursor() as $po) {
                $result = NetMarginEngine::forPurchaseOrder($po);

                $costs = [];
                foreach (array_keys(ProfitAndLoss::COST_LABELS) as $key) {
                    $costs[] = $result['cost_breakdown'][$key] ?? 0;
                }

                yield array_merge([
                    $po->po_number,
                    $po->order_date?->toDateString(),
                    $po->ship_to_fc,
                    Currency::code($result['currency']),
                    $result['billed'],
                    $result['invoice_costed'],
                    $result['back_margin_deducted'],
                    $result['net_receivable'],
                ], $costs, [
                    $result['cost'],
                    $result['profit'],
                    $result['margin_pct'],
                    $result['coverage']['lines_costed'],
                    $result['coverage']['lines_uncosted'],
                    $result['cost_basis'],
                ]);
            }
        };

        return CsvExport::stream('po-profit-and-loss', $headers, $rows(), [0],
            ['OperON — PO-level net P&L (Admin, PIN verified)',
                ...($filters->summary() ?: ['no filters applied']),
                'Revenue is the PO invoice less the marketplace back margin — what we bank, not what we billed',
                'Cost basis: '.config('operon.cost_basis').' supplier price (§S interim rule)']);
    }

    private function exportSkuMargin(FilterSet $filters, string $selector, $rows): StreamedResponse
    {
        $headers = ['Company Product Code', 'Name', 'Brand', 'Category', 'Channel', 'Currency',
            'Units shipped', 'RSP ex VAT', 'Net receivable / unit', 'Cost / unit',
            'Profit / unit', 'Margin %', 'Weighting'];

        $generator = function () use ($rows, $selector) {
            foreach ($rows as $row) {
                foreach ($row['channels'] as $channel) {
                    yield [
                        $row['code'], $row['name'], $row['brand'], $row['category'],
                        $channel['label'], Currency::code($channel['currency']),
                        $channel['units'], $channel['rsp_ex_vat'], $channel['net_receivable'],
                        $channel['cogs'], $channel['profit'], $channel['margin_pct'], '',
                    ];
                }

                // The blend is a row of its own so a reader never has to redo it — and
                // never redoes it as a simple mean, which is the mistake this guards.
                yield [
                    $row['code'], $row['name'], $row['brand'], $row['category'],
                    'BLENDED — '.SkuMargin::selectors()[$selector], Currency::code($row['currency']),
                    $row['blend']['units'], null, $row['blend']['net_receivable'],
                    $row['blend']['cogs'],
                    // Cost travels; the margin does not, for a price never charged.
                    $row['bundle_component'] ? null : $row['blend']['profit'],
                    $row['bundle_component'] ? \App\Models\Product::BUNDLE_MARGIN_LABEL : $row['blend']['margin_pct'],
                    $row['bundle_component']
                        ? 'bundle component — excluded from margin rankings'
                        : ($row['blend']['weight_basis'] === SkuMargin::BASIS_SHIPPED
                            ? 'revenue-weighted on units shipped'
                            : 'revenue-weighted per unit (nothing shipped yet)'),
                ];
            }
        };

        return CsvExport::stream('sku-margin', $headers, $generator(), [0],
            ['OperON — SKU-level net margin (Admin, PIN verified)',
                'Channel view: '.SkuMargin::selectors()[$selector],
                ...($filters->summary() ?: ['no filters applied']),
                'BLENDED rows are REVENUE-WEIGHTED, never a simple average of the channel percentages',
                'Unit costs are unit-weighted; cost basis: '.config('operon.cost_basis').' supplier price (§S interim rule)']);
    }
}
