<?php

namespace App\Http\Controllers;

use App\Enums\Stage;
use App\Models\Delivery;
use App\Models\ShipmentLine;
use App\Services\Import\Reconciler;
use App\Services\Reporting\CsvExport;
use App\Services\Reporting\FilterSet;
use App\Support\Currency;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The Shipments tab: deliveries by ASN, interim against final (§K, §L).
 *
 * One row per delivery. The interesting column is the shortfall - what was booked onto
 * the interim and did not make it onto the final - because that is the number the
 * warehouse and sales conversation is about, and it only exists once both stages are in.
 */
class ShipmentsController extends Controller
{
    public function __construct(private readonly Reconciler $reconciler) {}

    public function index(Request $request): View|RedirectResponse|StreamedResponse
    {
        $filters = FilterSet::fromRequest($request);

        if ($request->isMethod('post')) {
            return redirect()->route('shipments.index', $filters->query());
        }

        $stage = $request->query('stage'); // awaiting_final | shipped | short

        $query = $filters->applyToDeliveries(Delivery::query());

        $query = match ($stage) {
            'awaiting_final' => $query->awaitingFinal(),
            'shipped' => $query->where('has_final', true),
            'short' => $query->where('shortfall_units', '>', 0),
            default => $query,
        };

        if ($request->query('export') === 'csv') {
            return $this->export($request, $filters, clone $query, $stage);
        }

        return view('reports.shipments', [
            'filters' => $filters,
            'stage' => $stage,
            'deliveries' => $query->orderByRaw('COALESCE(delivered_on, planned_date) DESC')
                ->orderByDesc('id')
                ->paginate(50)
                ->withQueryString(),
        ]);
    }

    /** One delivery: the POs it bundles, and every SKU's interim against its final. */
    public function show(Delivery $delivery): View
    {
        // Interim and final side by side per SKU, which is where a shortfall becomes
        // attributable to a specific product rather than just a total (§L).
        $rows = ShipmentLine::where('delivery_id', $delivery->id)
            ->selectRaw('sku_id, MAX(title) as title, po_number,
                COALESCE(SUM(CASE WHEN stage = ? THEN qty ELSE 0 END), 0) as interim,
                COALESCE(SUM(CASE WHEN stage = ? THEN qty ELSE 0 END), 0) as final_qty,
                MAX(unit_cost) as unit_cost', [Stage::Interim->value, Stage::Final->value])
            ->groupBy('sku_id', 'po_number')
            ->orderBy('po_number')
            ->orderBy('sku_id')
            ->get();

        return view('reports.shipment-detail', [
            'delivery' => $delivery,
            'rows' => $rows,
            'poNumbers' => $delivery->poNumbers(),
        ]);
    }

    /**
     * Correct a delivery's date.
     *
     * The date decides the PO's turnaround, and it is not always right: an Amazon
     * packing list carries the date it was produced, and Noon's file has no reliable
     * date at all (§Q). Saving one marks it as manually set - so a later re-upload of
     * the packing list cannot quietly overwrite what a person entered - and recomputes
     * the turnaround of every PO in the delivery.
     */
    public function updateDate(Request $request, Delivery $delivery): RedirectResponse
    {
        $validated = $request->validate([
            'delivered_on' => ['required', 'date'],
        ]);

        $delivery->update([
            'delivered_on' => $validated['delivered_on'],
            'delivery_date_is_manual' => true,
        ]);

        $this->reconciler->recomputeTurnaround($delivery->marketplace, $delivery->poNumbers());

        return back()->with('status',
            'Delivery date set to '.$delivery->fresh()->delivered_on->format('d M Y')
            .'. Turnaround has been recalculated for '.count($delivery->poNumbers()).' PO(s).');
    }

    private function export(Request $request, FilterSet $filters, $query, ?string $stage): StreamedResponse
    {
        $showValue = $request->user()->canSeeOrderValue();

        $headers = ['ASN', 'Reference', 'FC', 'Planned date', 'Delivered on', 'Interim units',
            'Final units', 'Shortfall units', 'Stage'];

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
                    $delivery->planned_date?->toDateString(),
                    $delivery->delivered_on?->toDateString(),
                    $delivery->units_interim, $delivery->units_final, $delivery->shortfall_units,
                    $delivery->has_final ? 'Shipped' : ($delivery->has_interim ? 'Awaiting final' : '—'),
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

        return CsvExport::stream('shipments', $headers, $rows(), [0],
            ['OperON — Shipments', ...($filters->summary() ?: ['no filters applied']),
                $stage ? "Showing: {$stage}" : 'all deliveries']);
    }
}
