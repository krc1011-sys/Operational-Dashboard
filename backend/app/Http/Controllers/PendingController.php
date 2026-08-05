<?php

namespace App\Http\Controllers;

use App\Services\Reporting\CsvExport;
use App\Services\Reporting\FilterSet;
use App\Services\Reporting\FulfilmentQuery;
use App\Support\Currency;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The Pending tab: accepted units that are not booked into any delivery yet (§F).
 *
 * Not-booked = net accepted − booked. It is the work not yet scheduled, so it is the
 * list procurement and the warehouse plan from, biggest first. A line only leaves this
 * screen by being booked onto an interim packing list or by being cancelled.
 */
class PendingController extends Controller
{
    public function index(Request $request): View|RedirectResponse|StreamedResponse
    {
        $filters = FilterSet::fromRequest($request);

        if ($request->isMethod('post')) {
            return redirect()->route('pending.index', $filters->query());
        }

        $engine = new FulfilmentQuery($filters);
        $query = $engine->lines()->where('qty_not_booked', '>', 0);

        if ($request->query('export') === 'csv') {
            return $this->export($request, $filters, clone $query);
        }

        return view('reports.pending', [
            'filters' => $filters,
            'totals' => $engine->totals(),
            'lines' => $query
                ->with('purchaseOrder:id,po_number,order_date')
                ->orderByDesc('qty_not_booked')
                ->paginate(50)
                ->withQueryString(),
        ]);
    }

    private function export(Request $request, FilterSet $filters, $query): StreamedResponse
    {
        $showValue = $request->user()->canSeeOrderValue();

        $headers = ['PO', 'ASIN/NIN', 'Title', 'FC', 'Net accepted', 'Booked', 'Not booked', 'Expected date'];

        if ($showValue) {
            $headers[] = 'Currency';
            $headers[] = 'Value not booked';
        }

        $rows = function () use ($query, $showValue) {
            foreach ($query->orderByDesc('qty_not_booked')->cursor() as $line) {
                $row = [
                    $line->po_number, $line->sku_id, $line->title, $line->ship_to_fc,
                    $line->qty_net_accepted, $line->qty_booked, $line->qty_not_booked,
                    $line->expected_date?->toDateString(),
                ];

                if ($showValue) {
                    $row[] = Currency::code($line->currency);
                    $row[] = round($line->qty_not_booked * (float) $line->unit_cost, 2);
                }

                yield $row;
            }
        };

        return CsvExport::stream('pending', $headers, $rows(), [0, 1],
            ['OperON — Pending (not yet booked)', ...($filters->summary() ?: ['no filters applied'])]);
    }
}
