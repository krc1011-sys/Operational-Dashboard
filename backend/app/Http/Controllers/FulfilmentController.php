<?php

namespace App\Http\Controllers;

use App\Models\PoLine;
use App\Services\Reporting\CsvExport;
use App\Services\Reporting\FilterSet;
use App\Services\Reporting\FulfilmentQuery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The Fulfilment tab: fill rate and shortfall, per PO line or rolled up (§E, §L, §M).
 *
 * This is the screen the shortfall conversation happens over - "what did we plan to ship
 * and not ship, and which SKUs" - so it defaults to the worst offenders first and can be
 * grouped by SKU, brand or category and exported for whoever needs chasing.
 */
class FulfilmentController extends Controller
{
    public function index(Request $request): View|RedirectResponse|StreamedResponse
    {
        $filters = FilterSet::fromRequest($request);

        if ($request->isMethod('post')) {
            return redirect()->route('fulfilment.index', $filters->query());
        }

        $engine = new FulfilmentQuery($filters);

        if ($request->query('export') === 'csv') {
            return $this->export($request, $filters, $engine);
        }

        $grouped = $filters->groupBy !== FilterSet::GROUP_NONE
            ? $engine->grouped($filters->groupBy)
            : null;

        return view('reports.fulfilment', [
            'filters' => $filters,
            'totals' => $engine->totals(),
            'grouped' => $grouped,
            'lines' => $grouped !== null ? null : $engine->lines()
                ->with('purchaseOrder:id,po_number,order_date')
                ->orderByRaw('(qty_net_accepted - qty_shipped) DESC')
                ->orderBy('po_number')
                ->paginate(50)
                ->withQueryString(),
        ]);
    }

    private function export(Request $request, FilterSet $filters, FulfilmentQuery $engine): StreamedResponse
    {
        $showValue = $request->user()->canSeeOrderValue();
        $notes = ['OperON — Fulfilment', ...($filters->summary() ?: ['no filters applied'])];

        if ($filters->groupBy !== FilterSet::GROUP_NONE) {
            $headers = [ucfirst($filters->groupBy), 'SKUs', 'Accepted', 'Net accepted', 'Booked',
                'Shipped', 'Fill rate %', 'Shortfall units'];

            if ($showValue) {
                $headers[] = 'Shortfall AED';
            }

            $rows = $engine->grouped($filters->groupBy)->map(function (array $row) use ($showValue) {
                $out = [$row['key'], $row['sku_count'], $row['accepted'], $row['net_accepted'],
                    $row['booked'], $row['shipped'], $row['fill_rate'], $row['shortfall_units']];

                if ($showValue) {
                    $out[] = round($row['shortfall_value'], 2);
                }

                return $out;
            });

            return CsvExport::stream('fulfilment-by-'.$filters->groupBy, $headers, $rows, [0], $notes);
        }

        $headers = ['PO', 'ASIN/NIN', 'Title', 'FC', 'Status', 'Accepted', 'Cancelled',
            'Net accepted', 'Booked', 'Shipped', 'Not booked', 'Fill rate %', 'Shortfall units'];

        if ($showValue) {
            $headers[] = 'Unit cost';
            $headers[] = 'Shortfall AED';
        }

        $rows = function () use ($engine, $showValue) {
            foreach ($engine->lines()->orderBy('po_number')->orderBy('sku_id')->cursor() as $line) {
                $short = max(0, $line->qty_net_accepted - $line->qty_shipped);

                $row = [
                    $line->po_number, $line->sku_id, $line->title, $line->ship_to_fc,
                    FilterSet::lineStates()[$line->line_state] ?? $line->line_state,
                    $line->qty_accepted, $line->qty_cancelled_honoured, $line->qty_net_accepted,
                    $line->qty_booked, $line->qty_shipped, $line->qty_not_booked,
                    $line->fill_rate_pct, $short,
                ];

                if ($showValue) {
                    $row[] = (float) $line->unit_cost;
                    $row[] = round($short * (float) $line->unit_cost, 2);
                }

                yield $row;
            }
        };

        return CsvExport::stream('fulfilment', $headers, $rows(), [0, 1], $notes);
    }
}
