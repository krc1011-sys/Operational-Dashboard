<?php

namespace App\Http\Controllers;

use App\Enums\Stage;
use App\Models\Delivery;
use App\Models\PoLine;
use App\Models\PurchaseOrder;
use App\Models\ShipmentLine;
use App\Services\Margin\ProfitAndLoss;
use App\Services\Reporting\CsvExport;
use App\Services\Reporting\FilterSet;
use App\Support\MoneyGate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The PO Lookup tab (§L).
 *
 * The blueprint's requirement is specific: "searching a PO shows all linked ISAs, each
 * with its date and units, plus the overall days-to-complete". That is what the detail
 * screen is - one PO, every delivery its units went into, and the turnaround clock.
 *
 * The list is the same thing across many POs, with the turnaround benchmark colouring
 * the ones that need attention.
 */
class PoLookupController extends Controller
{
    public function index(Request $request): View|RedirectResponse|StreamedResponse
    {
        $filters = FilterSet::fromRequest($request);

        if ($request->isMethod('post')) {
            return redirect()->route('po-lookup.index', $filters->query());
        }

        // "Status" means something different here than on a line screen: it is the PO's
        // own state, not a line's.
        $status = $request->query('po_status');

        $query = $filters->applyToPurchaseOrders(PurchaseOrder::query())
            ->withCount('lines')
            ->withSum('lines as accepted', 'qty_net_accepted')
            ->withSum('lines as shipped', 'qty_shipped');

        $query = match ($status) {
            'open' => $query->where('is_complete', false),
            'complete' => $query->where('is_complete', true),
            'late' => $query->breachingBenchmark(),
            default => $query,
        };

        if ($request->query('export') === 'csv') {
            return $this->export($filters, clone $query, $status);
        }

        return view('reports.po-lookup', [
            'filters' => $filters,
            'poStatus' => $status,
            'orders' => $query->orderByDesc('order_date')->orderBy('po_number')
                ->paginate(50)->withQueryString(),
            'benchmark' => (int) config('operon.benchmarks.turnaround_days'),
        ]);
    }

    /** One PO: its header, every delivery it went into, and every line (§L). */
    public function show(Request $request, string $poNumber): View
    {
        $order = PurchaseOrder::where('po_number', $poNumber)->firstOrFail();

        // Units of THIS PO in each delivery, split by stage - a delivery bundles several
        // POs, so its own totals would overstate this one's contribution.
        $byDelivery = ShipmentLine::query()
            ->where('marketplace', $order->marketplace->value)
            ->where('po_number', $order->po_number)
            ->selectRaw('delivery_id, stage, SUM(qty) as units, COUNT(*) as rows_count')
            ->groupBy('delivery_id', 'stage')
            ->get()
            ->groupBy('delivery_id');

        $deliveries = Delivery::whereIn('id', $byDelivery->keys())->get()->keyBy('id');

        /*
         * The M7 inline unlock (§Profitability).
         *
         * The PIN is NOT on this route and must not be: §O gives this screen to roles
         * with no money permission at all, and bouncing them to a PIN prompt would take a
         * screen away to protect columns they were never going to be shown. So the screen
         * stays open and simply grows a P&L panel and three extra columns for someone who
         * holds `view-margin` and has the PIN in.
         *
         * Order value - units x the marketplace's own unit price - is NOT part of this.
         * It stays visible to every role, PIN or no PIN (User::canSeeOrderValue).
         */
        $canSeeMargin = MoneyGate::canSeeMargin();

        return view('reports.po-detail', [
            'order' => $order,
            'lines' => PoLine::where('marketplace', $order->marketplace->value)
                ->where('po_number', $order->po_number)
                ->orderBy('sku_id')
                ->with('product.economics')
                ->get(),
            'canSeeMargin' => $canSeeMargin,
            'pnl' => $canSeeMargin ? ProfitAndLoss::forPurchaseOrder($order) : null,
            'deliveries' => $deliveries,
            'byDelivery' => $byDelivery,
            'benchmark' => (int) config('operon.benchmarks.turnaround_days'),
            'interim' => Stage::Interim,
            'final' => Stage::Final,
        ]);
    }

    private function export(FilterSet $filters, $query, ?string $status): StreamedResponse
    {
        $headers = ['PO', 'Order date', 'FC', 'Vendor', 'Lines', 'Net accepted', 'Shipped',
            'Fill rate %', 'First shipped', 'Complete', 'Completed on', 'Days to complete', 'Days open'];

        $rows = function () use ($query) {
            foreach ($query->orderBy('po_number')->cursor() as $po) {
                $accepted = (int) $po->accepted;

                yield [
                    $po->po_number,
                    $po->order_date?->toDateString(),
                    $po->ship_to_fc,
                    $po->vendor_code,
                    $po->lines_count,
                    $accepted,
                    (int) $po->shipped,
                    $accepted > 0 ? round((int) $po->shipped / $accepted * 100, 2) : null,
                    $po->first_shipped_on?->toDateString(),
                    $po->is_complete ? 'yes' : 'no',
                    $po->completed_on?->toDateString(),
                    $po->days_to_complete,
                    $po->daysOpen(),
                ];
            }
        };

        return CsvExport::stream('po-lookup', $headers, $rows(), [0],
            ['OperON — PO lookup', ...($filters->summary() ?: ['no filters applied']),
                $status ? "PO status: {$status}" : 'all PO statuses']);
    }
}
