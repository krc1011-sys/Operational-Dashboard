<?php

namespace App\Http\Controllers;

use App\Models\PoLine;
use App\Models\PurchaseOrder;
use App\Services\Reporting\CsvExport;
use App\Services\Reporting\FilterSet;
use App\Services\Reporting\FulfilmentQuery;
use App\Support\Currency;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Fulfilment — a PO-centric drill (DESIGN_BRIEF §8).
 *
 * The screen lists PURCHASE ORDERS rather than hundreds of loose lines, because a PO is
 * the thing the team actually chases: Amazon sends one, we book deliveries against it,
 * and it closes. Open a PO to see its lines and where each one has got to.
 *
 * THE PENDING TAB IS MERGED IN as the "Not booked" status. It was the same lines under a
 * different filter, and two tabs answering one question is how people end up trusting the
 * wrong one.
 *
 * PERMISSIONS ARE UNCHANGED, WHICH TAKES CARE HERE. §O gives Sales `view-pending` but NOT
 * `view-fulfillment`. Folding Pending in would have quietly taken it away from Sales, so
 * the route accepts either permission and a user who holds only `view-pending` is held to
 * the not-booked view - exactly the rows their old tab showed, and nothing more.
 */
class FulfilmentController extends Controller
{
    public const STATUS_ALL = 'all';

    /** Accepted units sitting on no delivery - the old Pending tab. */
    public const STATUS_OUTSTANDING = 'outstanding';

    public const STATUS_BOOKED = 'booked';

    public const STATUS_SHIPPED = 'shipped';

    public const STATUS_CANCELLED = 'cancelled';

    public function index(Request $request): View|RedirectResponse|StreamedResponse
    {
        $filters = FilterSet::fromRequest($request);
        $status = $this->resolveStatus($request);

        if ($request->isMethod('post')) {
            return redirect()->route('fulfilment.index', $filters->query() + ['view' => $status]);
        }

        $engine = new FulfilmentQuery($filters);

        if ($request->query('export') === 'csv') {
            return $this->export($request, $filters, $engine, $status);
        }

        $expand = $request->string('expand')->toString();

        return view('reports.fulfilment', [
            'filters' => $filters,
            'status' => $status,
            'statuses' => $this->availableStatuses($request),
            'counts' => $this->statusCounts($filters, $request),
            'totals' => $engine->totals(),
            'orders' => $this->orders($filters, $status),
            // One PO open at a time, rendered on the server, so a drill is a shareable
            // link rather than a state that vanishes on refresh.
            'expanded' => $expand ?: null,
            'expandedLines' => $this->linesFor($expand, $filters, $status),
            'benchmarks' => config('operon.benchmarks'),
        ]);
    }

    /**
     * The POs in view, each carrying its rolled-up line figures.
     *
     * Rolled up in SQL from the same cached columns the engine wrote, so this cannot
     * invent a number that the engine would disagree with.
     */
    private function orders(FilterSet $filters, string $status)
    {
        $poNumbers = $this->linesMatching($filters, $status)
            ->select('po_number')->distinct()->pluck('po_number');

        if ($poNumbers->isEmpty()) {
            return collect();
        }

        $figures = $this->linesMatching($filters, $status)
            ->selectRaw('
                po_number,
                COUNT(*) as line_count,
                COALESCE(SUM(qty_accepted),0) as accepted,
                COALESCE(SUM(qty_net_accepted),0) as net_accepted,
                COALESCE(SUM(qty_booked),0) as booked,
                COALESCE(SUM(qty_shipped),0) as shipped,
                COALESCE(SUM(qty_not_booked),0) as not_booked,
                COALESCE(SUM(qty_cancelled_honoured),0) as cancelled,
                COALESCE(SUM(qty_net_accepted * unit_cost),0) as value,
                COALESCE(SUM(CASE WHEN qty_net_accepted > qty_shipped
                    THEN (qty_net_accepted - qty_shipped) * unit_cost ELSE 0 END),0) as shortfall_value,
                MAX(currency) as currency,
                MAX(ship_to_fc) as fc,
                COUNT(DISTINCT ship_to_fc) as fc_count,
                MAX(has_chargeback_flag) as chargeback
            ')
            ->groupBy('po_number')
            ->get()
            ->keyBy('po_number');

        return $filters->applyToPurchaseOrders(PurchaseOrder::query())
            ->whereIn('po_number', $poNumbers)
            ->get()
            ->map(function (PurchaseOrder $po) use ($figures) {
                $f = $figures->get($po->po_number);

                $po->setAttribute('figures', [
                    'lines' => (int) ($f->line_count ?? 0),
                    'accepted' => (int) ($f->accepted ?? 0),
                    'net_accepted' => (int) ($f->net_accepted ?? 0),
                    'booked' => (int) ($f->booked ?? 0),
                    'shipped' => (int) ($f->shipped ?? 0),
                    'not_booked' => (int) ($f->not_booked ?? 0),
                    'cancelled' => (int) ($f->cancelled ?? 0),
                    'value' => (float) ($f->value ?? 0),
                    'shortfall_value' => (float) ($f->shortfall_value ?? 0),
                    'currency' => Currency::code($f->currency ?? null),
                    'fc' => $f->fc ?? null,
                    'fc_count' => (int) ($f->fc_count ?? 0),
                    'chargeback' => (bool) ($f->chargeback ?? false),
                    'fill_rate' => ($f && $f->net_accepted > 0)
                        ? round((int) $f->shipped / (int) $f->net_accepted * 100, 1) : null,
                ]);

                return $po;
            })
            // Worst first: the biggest unshipped value is what to chase today (§1).
            ->sortByDesc(fn (PurchaseOrder $po) => $po->figures['shortfall_value'])
            ->values();
    }

    /** The lines behind one PO, narrowed exactly as the list above it was. */
    private function linesFor(string $poNumber, FilterSet $filters, string $status)
    {
        if ($poNumber === '') {
            return collect();
        }

        return $this->linesMatching($filters, $status)
            ->where('po_number', $poNumber)
            ->orderByRaw('(qty_net_accepted - qty_shipped) DESC')
            ->orderBy('sku_id')
            ->get();
    }

    /**
     * What each status means, in one place, so the list, the drill, the counts and the
     * export cannot drift apart.
     */
    private function linesMatching(FilterSet $filters, string $status)
    {
        $query = $filters->applyToLines(PoLine::query());

        return match ($status) {
            self::STATUS_OUTSTANDING => $query->where('qty_not_booked', '>', 0),
            self::STATUS_BOOKED => $query->where('qty_booked', '>', 0)->whereColumn('qty_shipped', '<', 'qty_booked'),
            self::STATUS_SHIPPED => $query->where('qty_shipped', '>', 0),
            self::STATUS_CANCELLED => $query->where('qty_cancelled_honoured', '>', 0),
            default => $query,
        };
    }

    /** @return array<string, int> */
    private function statusCounts(FilterSet $filters, Request $request): array
    {
        $counts = [];

        foreach (array_keys($this->availableStatuses($request)) as $status) {
            $counts[$status] = $this->linesMatching($filters, $status)->count();
        }

        return $counts;
    }

    /**
     * Which statuses this user may switch between.
     *
     * Someone holding only `view-pending` gets the not-booked view and no toggle, because
     * that is precisely the screen §O granted them.
     *
     * @return array<string, string>
     */
    private function availableStatuses(Request $request): array
    {
        $all = [
            self::STATUS_ALL => 'All',
            self::STATUS_OUTSTANDING => 'Not booked',
            self::STATUS_BOOKED => 'Booked',
            self::STATUS_SHIPPED => 'Shipped',
            self::STATUS_CANCELLED => 'Cancelled',
        ];

        return $request->user()->can('view-fulfillment')
            ? $all
            : [self::STATUS_OUTSTANDING => $all[self::STATUS_OUTSTANDING]];
    }

    private function resolveStatus(Request $request): string
    {
        $available = $this->availableStatuses($request);
        // Deliberately NOT `status`: FilterSet already owns that name for the per-line
        // state, and two filters sharing a query parameter narrow each other to nothing.
        $asked = $request->string('view')->toString();

        if (isset($available[$asked])) {
            return $asked;
        }

        return $request->user()->can('view-fulfillment') ? self::STATUS_ALL : self::STATUS_OUTSTANDING;
    }

    private function export(Request $request, FilterSet $filters, FulfilmentQuery $engine, string $status): StreamedResponse
    {
        $showValue = $request->user()->canSeeOrderValue();
        $notes = ['OperON — Fulfilment',
            'Status: '.($this->availableStatuses($request)[$status] ?? $status),
            ...($filters->summary() ?: ['no filters applied'])];

        if ($filters->groupBy !== FilterSet::GROUP_NONE) {
            $headers = [ucfirst($filters->groupBy), 'SKUs', 'Accepted', 'Net accepted', 'Booked',
                'Shipped', 'Fill rate %', 'Shortfall units'];

            if ($showValue) {
                $headers[] = 'Currency';
                $headers[] = 'Shortfall value';
            }

            $rows = $engine->grouped($filters->groupBy)->map(function (array $row) use ($showValue) {
                $out = [$row['key'], $row['sku_count'], $row['accepted'], $row['net_accepted'],
                    $row['booked'], $row['shipped'], $row['fill_rate'], $row['shortfall_units']];

                if ($showValue) {
                    // A group whose lines span currencies says so rather than presenting a
                    // total that silently added dirhams to something else.
                    $out[] = $row['currency'] ?? 'mixed';
                    $out[] = round($row['shortfall_value'], 2);
                }

                return $out;
            });

            return CsvExport::stream('fulfilment-by-'.$filters->groupBy, $headers, $rows, [0], $notes);
        }

        $headers = ['PO', 'ASIN/NIN', 'Title', 'FC', 'Status', 'Accepted', 'Cancelled',
            'Net accepted', 'Booked', 'Shipped', 'Not booked', 'Fill rate %', 'Shortfall units'];

        if ($showValue) {
            $headers[] = 'Currency';
            $headers[] = 'Unit cost';
            $headers[] = 'Shortfall value';
        }

        $query = $this->linesMatching($filters, $status);

        $rows = function () use ($query, $showValue) {
            foreach ($query->orderBy('po_number')->orderBy('sku_id')->cursor() as $line) {
                $short = max(0, $line->qty_net_accepted - $line->qty_shipped);

                $row = [
                    $line->po_number, $line->sku_id, $line->title, $line->ship_to_fc,
                    FilterSet::lineStates()[$line->line_state] ?? $line->line_state,
                    $line->qty_accepted, $line->qty_cancelled_honoured, $line->qty_net_accepted,
                    $line->qty_booked, $line->qty_shipped, $line->qty_not_booked,
                    $line->fill_rate_pct, $short,
                ];

                if ($showValue) {
                    $row[] = Currency::code($line->currency);
                    $row[] = (float) $line->unit_cost;
                    $row[] = round($short * (float) $line->unit_cost, 2);
                }

                yield $row;
            }
        };

        return CsvExport::stream('fulfilment', $headers, $rows(), [0, 1], $notes);
    }
}
