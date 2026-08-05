<?php

namespace App\Http\Controllers;

use App\Models\Cancellation;
use App\Models\Delivery;
use App\Models\PurchaseOrder;
use App\Services\Reporting\FilterSet;
use App\Services\Reporting\FulfilmentQuery;
use App\Services\Reporting\OverviewPanels;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The Overview tab: the numbers the business is managed by, on one screen (§M).
 *
 * Modelled on the pattern Amazon Vendor Central itself uses, because the team already
 * reads it every day: a row of operational tiles, a row of performance tiles, each
 * against its own target, each clicking through to the screen that explains it.
 *
 * The thresholds are Amazon's own (§M): 95% in-full, ~80% confirmation, 10-day
 * turnaround. They live in config/operon.php, not in this file.
 */
class OverviewController extends Controller
{
    public function index(Request $request): View|RedirectResponse
    {
        $filters = FilterSet::fromRequest($request);

        if ($request->isMethod('post')) {
            return redirect()->route('overview.index', $filters->query());
        }

        $engine = new FulfilmentQuery($filters);
        $totals = $engine->totals();
        $benchmarks = config('operon.benchmarks');

        // Turnaround across the POs the filter covers.
        $orders = $filters->applyToPurchaseOrders(PurchaseOrder::query());

        $completed = (clone $orders)->where('is_complete', true)->whereNotNull('days_to_complete');
        $averageDays = $completed->count() > 0 ? round((float) $completed->avg('days_to_complete'), 1) : null;

        $open = (clone $orders)->where('is_complete', false);
        $late = (clone $open)->breachingBenchmark()->count();

        // The v3 panels. Read-only views over the same cached columns (DESIGN_BRIEF §8).
        $panels = new OverviewPanels($filters);

        return view('reports.overview', [
            'filters' => $filters,
            'totals' => $totals,
            'benchmarks' => $benchmarks,
            'averageDays' => $averageDays,
            'completedCount' => $completed->count(),
            'openCount' => $open->count(),
            'lateCount' => $late,
            'awaitingFinal' => Delivery::awaitingFinal()->count(),
            'needsDecision' => Cancellation::needsDecision()->count(),
            'chargebackUnits' => (int) Cancellation::chargebackExposure()->sum('qty_delivered_anyway'),
            'fcs' => $panels->fulfilmentCentres(),
            'channels' => $panels->channelMix(),
            'sellThrough' => $panels->sellThrough(),
            'alerts' => $panels->alerts(),
            'inFlight' => $panels->inFlight(),
            'coverage' => $panels->catalogCoverage(),
        ]);
    }
}
