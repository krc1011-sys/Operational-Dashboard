<?php

namespace App\Http\Controllers;

use App\Enums\CancellationResolution;
use App\Models\Cancellation;
use App\Services\Import\CancellationDecider;
use App\Services\Import\Reconciler;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * The Cancellations tab, and with it the "deliver anyway or pull it" workflow (§G).
 *
 * Most cancellations never appear here: if the units were still free, they were netted
 * off automatically at upload. What lands on this screen is the awkward case - Amazon
 * has cancelled units we have already booked into a delivery, or already shipped. The
 * system deliberately refuses to guess, nets nothing, and asks:
 *
 *   Deliver anyway - we send it regardless. It still counts as delivered, so the line
 *                    can read 100%, and we accept the chargeback risk.
 *   Pull it        - take it back out of the delivery. Anything already shipped cannot
 *                    be pulled, so that part stays counted as delivered anyway.
 *
 * Seeing the queue needs `view-cancelled-items`; answering it needs `manage-fulfillment`,
 * so Finance can watch the exposure without being able to commit us to a shipment.
 */
class CancellationDecisionController extends Controller
{
    public function __construct(
        private readonly CancellationDecider $decider,
        private readonly Reconciler $reconciler,
    ) {}

    public function index(Request $request): View
    {
        $pending = Cancellation::needsDecision()
            ->with('poLine')
            ->orderBy('po_number')
            ->orderBy('sku_id')
            ->get();

        return view('cancellations.index', [
            'pending' => $pending,
            'exposure' => Cancellation::chargebackExposure()
                ->with(['poLine', 'resolvedBy'])
                ->orderByDesc('resolved_at')
                ->limit(50)
                ->get(),
            'waiting' => Cancellation::where('is_unmatched', true)->count(),
            'netted' => (int) Cancellation::sum('qty_honoured'),
            'canDecide' => $request->user()->can('manage-fulfillment'),
            'decider' => $this->decider,
        ]);
    }

    public function decide(Request $request, Cancellation $cancellation): RedirectResponse
    {
        $validated = $request->validate([
            'choice' => ['required', Rule::in([
                CancellationResolution::DeliveredAnyway->value,
                CancellationResolution::PulledBack->value,
            ])],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        // Only a parked question, or a previous answer being changed, can be answered.
        // A cancellation that netted automatically has nothing to decide.
        if ($cancellation->resolution !== CancellationResolution::NeedsDecision
            && ! $cancellation->isResolvedByHuman()) {
            return back()->with('error',
                'That cancellation did not need a decision - its units were still free, so it '
                .'was netted off automatically.');
        }

        $choice = CancellationResolution::from($validated['choice']);

        $this->decider->apply(
            $cancellation->load('poLine'),
            $choice,
            $request->user(),
            $validated['note'] ?? null,
        );

        // The decision changes net accepted, fill rate, the chargeback flag and possibly
        // whether the PO is now complete - so recompute rather than patching one column.
        $this->reconciler->recomputePoLinesFor(
            $cancellation->marketplace,
            [$cancellation->po_number],
            [$cancellation->sku_id],
        );

        return back()->with('status', $this->outcome($cancellation->fresh()));
    }

    /** Say in plain words what the decision actually did to the numbers. */
    private function outcome(Cancellation $cancellation): string
    {
        $line = "{$cancellation->po_number} / {$cancellation->sku_id}: ";

        if ($cancellation->resolution === CancellationResolution::DeliveredAnyway) {
            return $line."delivering all {$cancellation->qty_cancelled} cancelled units anyway. "
                .'They stay accepted and count as delivered, and the line is now flagged for '
                .'chargeback exposure.';
        }

        if ($cancellation->qty_delivered_anyway > 0) {
            return $line."{$cancellation->qty_honoured} unit(s) pulled back and netted off accepted. "
                ."The other {$cancellation->qty_delivered_anyway} had already shipped and cannot be "
                .'pulled, so they stay counted as delivered and the line is flagged for chargeback '
                .'exposure. Tell the warehouse to leave the pulled units off the delivery.';
        }

        return $line."all {$cancellation->qty_honoured} unit(s) pulled back and netted off accepted. "
            .'Nothing had shipped. Tell the warehouse to leave them off the delivery.';
    }
}
