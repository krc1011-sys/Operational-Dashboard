<?php

namespace App\Http\Controllers;

use App\Enums\Stage;
use App\Models\Delivery;
use App\Models\ShipmentLine;
use App\Services\Reporting\CsvExport;
use App\Services\Reporting\FilterSet;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * "Upcoming committed deliveries" (§R) - the answer to the DFS overstock trap.
 *
 * The trap, in the blueprint's words: the team orders DFS holding inventory from what
 * sales says is moving, and misses that POs are already booked to ship the same SKUs
 * next week. They over-order, and the stock sits.
 *
 * So this screen answers exactly one question, per ASIN: HOW MANY UNITS ARE ALREADY
 * COMMITTED TO GO OUT? "Committed" means booked onto an interim packing list for a
 * delivery that has not shipped yet - a final packing list means those units have gone
 * and are no longer something to net a new order against.
 *
 * It is built around pasting a list of ASINs, because that is how the question actually
 * arrives: with the DFS order in front of you.
 */
class CommittedDeliveriesController extends Controller
{
    public function index(Request $request): View|RedirectResponse|StreamedResponse
    {
        $filters = FilterSet::fromRequest($request);

        if ($request->isMethod('post')) {
            return redirect()->route('committed.index', $filters->query());
        }

        $rows = $this->committed($filters);

        if ($request->query('export') === 'csv') {
            return $this->export($filters, $rows);
        }

        return view('reports.committed', [
            'filters' => $filters,
            'rows' => $rows,
            'deliveryCount' => $this->upcomingDeliveries($filters)->count(),
            'totalUnits' => $rows->sum('units'),
        ]);
    }

    /**
     * Units per SKU on interim packing lists whose delivery has not shipped yet.
     *
     * @return \Illuminate\Support\Collection
     */
    private function committed(FilterSet $filters)
    {
        $upcoming = $this->upcomingDeliveries($filters)->get()->keyBy('id');

        // Which delivery each SKU goes out on first - worked out once for every SKU
        // rather than a query per row, because a pasted list can be thousands long.
        $soonest = ShipmentLine::query()
            ->where('stage', Stage::Interim->value)
            ->whereIn('delivery_id', $upcoming->keys())
            ->select('sku_id', 'delivery_id')
            ->get()
            ->groupBy('sku_id')
            ->map(fn ($lines) => $lines
                ->map(fn ($line) => $upcoming->get($line->delivery_id))
                ->filter()
                ->sortBy(fn (Delivery $d) => $d->planned_date?->toDateString() ?? '9999-12-31')
                ->first());

        return ShipmentLine::query()
            ->where('stage', Stage::Interim->value)
            ->whereIn('delivery_id', $upcoming->keys())
            ->when($filters->skus !== [], fn ($q) => $q->whereIn('sku_id', $filters->skus))
            ->when($filters->search !== null, fn ($q) => $q->where(fn ($w) => $w
                ->where('sku_id', 'like', '%'.$filters->search.'%')
                ->orWhere('title', 'like', '%'.$filters->search.'%')))
            ->selectRaw('sku_id, MAX(title) as title,
                SUM(qty) as units,
                COUNT(DISTINCT delivery_id) as delivery_count,
                COUNT(DISTINCT po_number) as po_count')
            ->groupBy('sku_id')
            ->orderByDesc('units')
            ->get()
            ->map(function ($row) use ($soonest) {
                // The soonest one, so ordering decisions know how urgent this is.
                $next = $soonest->get($row->sku_id);

                return [
                    'sku_id' => $row->sku_id,
                    'title' => $row->title,
                    'units' => (int) $row->units,
                    'delivery_count' => (int) $row->delivery_count,
                    'po_count' => (int) $row->po_count,
                    'next_date' => $next?->planned_date,
                    'next_asn' => $next?->asn,
                    'next_fc' => $next?->fc_code,
                ];
            });
    }

    /**
     * Deliveries that are booked but have not shipped: an interim exists, no final yet.
     * The date range and FC filters apply here, so "what is committed in the next two
     * weeks" is a question this screen can answer.
     */
    private function upcomingDeliveries(FilterSet $filters)
    {
        return $filters->applyToDeliveries(Delivery::query())->awaitingFinal();
    }

    private function export(FilterSet $filters, $rows): StreamedResponse
    {
        $headers = ['ASIN/NIN', 'Title', 'Units already committed', 'Deliveries', 'POs',
            'Next delivery date', 'Next ASN', 'Next FC'];

        $csv = $rows->map(fn (array $r) => [
            $r['sku_id'], $r['title'], $r['units'], $r['delivery_count'], $r['po_count'],
            $r['next_date']?->toDateString(), $r['next_asn'], $r['next_fc'],
        ]);

        return CsvExport::stream('committed-deliveries', $headers, $csv, [0, 6],
            ['OperON — Upcoming committed deliveries',
                'Units booked onto interim packing lists for deliveries that have not shipped yet.',
                ...($filters->summary() ?: ['no filters applied'])]);
    }
}
