<?php

namespace App\Http\Controllers;

use App\Enums\Stage;
use App\Models\Delivery;
use App\Models\ShipmentLine;
use App\Services\Reporting\CsvExport;
use App\Services\Reporting\FilterSet;
use App\Support\Currency;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Deliveries — Shipments and Committed, merged (DESIGN_BRIEF §8).
 *
 * They were always two views of one thing. A delivery is booked (interim packing list),
 * then it ships (final). "Committed" was the booked half asked per-SKU; "Shipments" was
 * the whole list with the shipped half's shortfall. One screen, one toggle:
 *
 *   BOOKED   — on a delivery that has not gone yet. What is already committed to ship,
 *              which is the §R answer to the DFS overstock trap: before ordering DFS
 *              stock, check what is already on its way.
 *   SHIPPED  — gone, with the interim-vs-final shortfall attributable per SKU.
 *
 * Each delivery shows its FC and the POs bundled under its ASN, because one ASN carries
 * several POs and "which PO is in this truck" is the question the warehouse asks.
 *
 * PERMISSIONS ARE UNCHANGED. §O gives Sales `view-committed-deliveries` without
 * `view-shipments`, and Warehouse the reverse. Merging naively would have taken a screen
 * from each, so the route accepts either and each half of the toggle is gated on its own
 * permission - a user sees exactly the halves §O gave them.
 */
class DeliveriesController extends Controller
{
    public const VIEW_BOOKED = 'booked';

    public const VIEW_SHIPPED = 'shipped';

    public function index(Request $request): View|RedirectResponse|StreamedResponse
    {
        $filters = FilterSet::fromRequest($request);
        $view = $this->resolveView($request);

        if ($request->isMethod('post')) {
            return redirect()->route('deliveries.index', $filters->query() + ['view' => $view]);
        }

        $query = $filters->applyToDeliveries(Delivery::query());

        $query = $view === self::VIEW_BOOKED
            ? $query->awaitingFinal()          // booked, not yet gone
            : $query->where('has_final', true); // shipped

        if ($request->query('export') === 'csv') {
            return $this->export($request, $filters, clone $query, $view);
        }

        $deliveries = $query
            ->orderByRaw('COALESCE(delivered_on, planned_date) DESC')
            ->orderByDesc('id')
            ->paginate(50)
            ->withQueryString();

        return view('reports.deliveries', [
            'filters' => $filters,
            'view' => $view,
            'views' => $this->availableViews($request),
            'counts' => $this->viewCounts($filters, $request),
            'deliveries' => $deliveries,
            // The POs and per-SKU lines under each ASN, for the ones on this page.
            'breakdown' => $this->breakdownFor($deliveries->getCollection()),
            // §R: what is already committed per SKU, which is what the toggle's booked
            // half exists to answer.
            'committed' => $view === self::VIEW_BOOKED ? $this->committedPerSku($filters) : collect(),
            'expanded' => $request->string('expand')->toString() ?: null,
        ]);
    }

    /** One delivery: the POs it bundles, and every SKU's interim against its final. */
    public function show(Delivery $delivery): View
    {
        $rows = ShipmentLine::where('delivery_id', $delivery->id)
            ->selectRaw('sku_id, MAX(title) as title, po_number,
                COALESCE(SUM(CASE WHEN stage = ? THEN qty ELSE 0 END), 0) as interim,
                COALESCE(SUM(CASE WHEN stage = ? THEN qty ELSE 0 END), 0) as final_qty,
                MAX(unit_cost) as unit_cost', [Stage::Interim->value, Stage::Final->value])
            ->groupBy('sku_id', 'po_number')
            ->orderBy('po_number')
            ->orderBy('sku_id')
            ->get();

        return view('reports.delivery-detail', [
            'delivery' => $delivery,
            'rows' => $rows,
            'poNumbers' => $delivery->poNumbers(),
        ]);
    }

    /**
     * Per-SKU interim vs final for a page of deliveries, keyed by delivery id.
     *
     * One query for the whole page rather than one per row: the shortfall is only
     * meaningful per SKU (§L), and a screen that hides it behind a click hides the
     * number the conversation is actually about.
     */
    private function breakdownFor($deliveries): Collection
    {
        $ids = $deliveries->pluck('id');

        if ($ids->isEmpty()) {
            return collect();
        }

        return ShipmentLine::whereIn('delivery_id', $ids)
            ->selectRaw('delivery_id, po_number, sku_id, MAX(title) as title,
                COALESCE(SUM(CASE WHEN stage = ? THEN qty ELSE 0 END), 0) as interim,
                COALESCE(SUM(CASE WHEN stage = ? THEN qty ELSE 0 END), 0) as final_qty,
                MAX(unit_cost) as unit_cost, MAX(currency) as currency',
                [Stage::Interim->value, Stage::Final->value])
            ->groupBy('delivery_id', 'po_number', 'sku_id')
            ->orderBy('po_number')
            ->get()
            ->groupBy('delivery_id');
    }

    /**
     * Units per SKU already committed to a delivery that has not gone (§R).
     *
     * "Committed" deliberately excludes anything on a final packing list: those units
     * have left, and netting a new order against them would double-count.
     */
    private function committedPerSku(FilterSet $filters)
    {
        $deliveryIds = $filters->applyToDeliveries(Delivery::query())->awaitingFinal()->pluck('id');

        if ($deliveryIds->isEmpty()) {
            return collect();
        }

        return ShipmentLine::whereIn('delivery_id', $deliveryIds)
            ->where('stage', Stage::Interim->value)
            ->when($filters->skus !== [], fn ($q) => $q->whereIn('sku_id', $filters->skus))
            ->selectRaw('sku_id, MAX(title) as title, SUM(qty) as units,
                COUNT(DISTINCT delivery_id) as deliveries, COUNT(DISTINCT po_number) as pos')
            ->groupBy('sku_id')
            ->orderByDesc('units')
            ->limit(200)
            ->get();
    }

    /**
     * Which halves of the toggle this user may see.
     *
     * @return array<string, string>
     */
    private function availableViews(Request $request): array
    {
        $views = [];

        if ($request->user()->can('view-committed-deliveries')) {
            $views[self::VIEW_BOOKED] = 'Booked';
        }

        if ($request->user()->can('view-shipments')) {
            $views[self::VIEW_SHIPPED] = 'Shipped';
        }

        return $views;
    }

    private function resolveView(Request $request): string
    {
        $available = $this->availableViews($request);
        $asked = $request->string('view')->toString();

        if (isset($available[$asked])) {
            return $asked;
        }

        // Default to shipped for anyone who may see it; otherwise the half they hold.
        return array_key_first($available) === self::VIEW_BOOKED && count($available) === 1
            ? self::VIEW_BOOKED
            : (isset($available[self::VIEW_SHIPPED]) ? self::VIEW_SHIPPED : self::VIEW_BOOKED);
    }

    /** @return array<string, int> */
    private function viewCounts(FilterSet $filters, Request $request): array
    {
        $counts = [];

        foreach (array_keys($this->availableViews($request)) as $view) {
            $query = $filters->applyToDeliveries(Delivery::query());

            $counts[$view] = $view === self::VIEW_BOOKED
                ? $query->awaitingFinal()->count()
                : $query->where('has_final', true)->count();
        }

        return $counts;
    }

    private function export(Request $request, FilterSet $filters, $query, string $view): StreamedResponse
    {
        $showValue = $request->user()->canSeeOrderValue();

        $headers = ['ASN', 'Reference', 'FC', 'POs', 'Planned date', 'Delivered on',
            'Interim units', 'Final units', 'Shortfall units', 'Stage'];

        if ($showValue) {
            $headers[] = 'Currency';
            $headers[] = 'Interim value';
            $headers[] = 'Final value';
            $headers[] = 'Shortfall value';
        }

        $rows = function () use ($query, $showValue) {
            foreach ($query->orderBy('id')->cursor() as $delivery) {
                $row = [
                    $delivery->asn, $delivery->internal_ref, $delivery->fc_code,
                    implode(' ', $delivery->poNumbers()),
                    $delivery->planned_date?->toDateString(),
                    $delivery->delivered_on?->toDateString(),
                    $delivery->units_interim, $delivery->units_final, $delivery->shortfall_units,
                    $delivery->has_final ? 'Shipped' : ($delivery->has_interim ? 'Booked' : '—'),
                ];

                if ($showValue) {
                    $row[] = Currency::code($delivery->currency);
                    $row[] = round((float) $delivery->value_interim, 2);
                    $row[] = round((float) $delivery->value_final, 2);
                    $row[] = round((float) $delivery->shortfall_value, 2);
                }

                yield $row;
            }
        };

        return CsvExport::stream('deliveries-'.$view, $headers, $rows(), [0],
            ['OperON — Deliveries', 'Showing: '.$view, ...($filters->summary() ?: ['no filters applied'])]);
    }
}
