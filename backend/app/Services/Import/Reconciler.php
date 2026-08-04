<?php

namespace App\Services\Import;

use App\Enums\Marketplace;
use App\Enums\Stage;
use App\Models\Cancellation;
use App\Models\Delivery;
use App\Models\PoLine;
use App\Models\PurchaseOrder;
use App\Models\ShipmentLine;

/**
 * Keeps the cached reconciliation columns in step with what has been imported.
 *
 * Everything the engine produces is derived, never typed in: link the rows that arrive
 * out of order, then recompute booked / shipped / not-booked / net-accepted / fill rate
 * / line state per PO line, each delivery's totals and shortfall, and each PO's
 * turnaround and completion.
 *
 * The definitions themselves live on the models (PoLine::computeFillRate(),
 * PurchaseOrder::computeCompletedOn() and friends) and the §G rules live in
 * CancellationDecider, so this class decides WHEN to recompute, never WHAT the numbers
 * mean. That is what keeps the engine and the screens from drifting apart.
 *
 * Order matters inside a recompute, and it is deliberate:
 *
 *   1. booked and shipped, straight from the packing lines;
 *   2. re-run the automatic cancellation rules against those fresh figures, because a
 *      cancellation parked earlier may now be nettable (its PO finally arrived) or may
 *      now need asking about (units it wanted have since been booked);
 *   3. net accepted, not-booked, fill rate, line state, chargeback flag;
 *   4. the PO's turnaround and completion, which depend on all of the above.
 */
class Reconciler
{
    public function __construct(private readonly CancellationDecider $decider) {}

    /**
     * Link packing-list lines and cancellations that were waiting for a PO to arrive (§K).
     *
     * @param  string[]  $poNumbers
     * @return int how many rows were linked up
     */
    public function attachOrphanShipmentLines(Marketplace $marketplace, array $poNumbers): int
    {
        if ($poNumbers === []) {
            return 0;
        }

        $lines = PoLine::where('marketplace', $marketplace->value)
            ->whereIn('po_number', $poNumbers)
            ->get()
            ->keyBy(fn (PoLine $l) => $l->po_number.'|'.$l->sku_id);

        $linked = 0;

        ShipmentLine::where('marketplace', $marketplace->value)
            ->whereIn('po_number', $poNumbers)
            ->where('is_unmatched', true)
            ->chunkById(500, function ($orphans) use ($lines, &$linked) {
                foreach ($orphans as $orphan) {
                    $match = $lines->get($orphan->po_number.'|'.$orphan->sku_id);

                    if ($match !== null) {
                        $orphan->update([
                            'po_line_id' => $match->id,
                            'product_id' => $orphan->product_id ?? $match->product_id,
                            'is_unmatched' => false,
                        ]);
                        $linked++;
                    }
                }
            });

        Cancellation::where('marketplace', $marketplace->value)
            ->whereIn('po_number', $poNumbers)
            ->where('is_unmatched', true)
            ->chunkById(500, function ($orphans) use ($lines, &$linked) {
                foreach ($orphans as $orphan) {
                    $match = $lines->get($orphan->po_number.'|'.$orphan->sku_id);

                    if ($match !== null) {
                        $orphan->update([
                            'po_line_id' => $match->id,
                            'product_id' => $orphan->product_id ?? $match->product_id,
                            'is_unmatched' => false,
                        ]);
                        $linked++;
                    }
                }
            });

        $this->recomputePoLinesFor($marketplace, $poNumbers);

        return $linked;
    }

    /**
     * Recompute the cached columns for the lines of these POs.
     *
     * @param  string[]  $poNumbers
     * @param  string[]  $skuIds  optional narrowing, when only some SKUs were touched
     */
    public function recomputePoLinesFor(Marketplace $marketplace, array $poNumbers, array $skuIds = []): int
    {
        if ($poNumbers === []) {
            return 0;
        }

        // One query per fact table rather than per line.
        $shipped = ShipmentLine::query()
            ->where('marketplace', $marketplace->value)
            ->whereIn('po_number', $poNumbers)
            ->selectRaw('po_number, sku_id, stage, SUM(qty) as units')
            ->groupBy('po_number', 'sku_id', 'stage')
            ->get()
            ->groupBy(fn ($r) => $r->po_number.'|'.$r->sku_id);

        $cancellations = Cancellation::query()
            ->where('marketplace', $marketplace->value)
            ->whereIn('po_number', $poNumbers)
            ->get()
            ->keyBy(fn (Cancellation $c) => $c->po_number.'|'.$c->sku_id);

        $query = PoLine::where('marketplace', $marketplace->value)->whereIn('po_number', $poNumbers);

        if ($skuIds !== []) {
            $query->whereIn('sku_id', $skuIds);
        }

        $updated = 0;

        $query->chunkById(500, function ($lines) use ($shipped, $cancellations, &$updated) {
            foreach ($lines as $line) {
                $key = $line->po_number.'|'.$line->sku_id;
                $stages = $shipped->get($key);
                $cancellation = $cancellations->get($key);

                // 1. What the packing lists say.
                $line->qty_booked = (int) ($stages?->firstWhere('stage', Stage::Interim)?->units ?? 0);
                $line->qty_shipped = (int) ($stages?->firstWhere('stage', Stage::Final)?->units ?? 0);

                /*
                 * 2. Re-run the §G rules against those fresh figures. This is what makes
                 * a cancellation that arrived before its PO net by itself once the PO
                 * lands, exactly as the upload screen promises. Rows a person has
                 * already answered, and rows that have already netted, are left alone.
                 */
                if ($cancellation !== null) {
                    $this->decider->reevaluate($cancellation, $line);
                }

                // 3. Everything derived from the two together.
                $line->qty_cancelled_honoured = (int) ($cancellation?->qty_honoured ?? 0);
                $line->qty_net_accepted = $line->computeNetAccepted();
                $line->qty_not_booked = $line->computeNotBooked();
                $line->fill_rate_pct = $line->computeFillRate();
                $line->line_state = $line->computeState();
                $line->has_chargeback_flag = (bool) $cancellation?->isChargebackExposure();

                if ($line->isDirty()) {
                    $line->save();
                }

                $updated++;
            }
        });

        // 4. Turnaround and completion, which read the line figures just written.
        $this->recomputeTurnaround($marketplace, $poNumbers);

        return $updated;
    }

    /**
     * Turnaround and completion for these POs (§L).
     *
     * Headline turnaround = completion date - PO date, against a 10-day benchmark.
     * Completion = every line shipped at least its net accepted quantity, which also
     * covers "the remainder was cancelled" because net accepted already has honoured
     * cancellations taken off it.
     *
     * @param  string[]  $poNumbers
     */
    public function recomputeTurnaround(Marketplace $marketplace, array $poNumbers): int
    {
        if ($poNumbers === []) {
            return 0;
        }

        $updated = 0;

        PurchaseOrder::where('marketplace', $marketplace->value)
            ->whereIn('po_number', $poNumbers)
            ->chunkById(200, function ($orders) use (&$updated) {
                foreach ($orders as $order) {
                    $complete = $order->computeIsComplete();
                    $completedOn = $complete ? $order->computeCompletedOn() : null;

                    $order->first_shipped_on = $order->computeFirstShippedOn();
                    $order->is_complete = $complete;
                    $order->completed_on = $completedOn;
                    // A PO can be complete with no usable date (nothing carried one).
                    // It still counts as complete; it just has no days figure.
                    $order->days_to_complete = $order->computeDaysToComplete($completedOn);

                    if ($order->isDirty()) {
                        $order->save();
                    }

                    $updated++;
                }
            });

        return $updated;
    }

    /** Recompute every line of one PO. */
    public function recomputePurchaseOrder(?PurchaseOrder $purchaseOrder): void
    {
        if ($purchaseOrder === null) {
            return;
        }

        $this->recomputePoLinesFor($purchaseOrder->marketplace, [$purchaseOrder->po_number]);
    }

    /**
     * Totals and shortfall for one delivery (§L), plus the FC sanity check.
     *
     * The FC is deliberately absent from packing lists, so it is derived from the POs
     * in the delivery. They should all share one FC; if not, that is flagged rather
     * than guessed at.
     */
    public function recomputeDelivery(?Delivery $delivery): void
    {
        if ($delivery === null) {
            return;
        }

        $totals = ShipmentLine::query()
            ->where('delivery_id', $delivery->id)
            ->selectRaw('stage, SUM(qty) as units, SUM(COALESCE(line_value, 0)) as value')
            ->groupBy('stage')
            ->get()
            ->keyBy(fn ($r) => $r->stage instanceof Stage ? $r->stage->value : $r->stage);

        $delivery->units_interim = (int) ($totals[Stage::Interim->value]->units ?? 0);
        $delivery->units_final = (int) ($totals[Stage::Final->value]->units ?? 0);

        // Prefer the sheet's own "Invoice value" banner when we captured it, since that
        // is the figure the accounts work from; otherwise sum our line values.
        $delivery->value_interim = $delivery->value_interim
            ?: (float) ($totals[Stage::Interim->value]->value ?? 0);
        $delivery->value_final = $delivery->value_final
            ?: (float) ($totals[Stage::Final->value]->value ?? 0);

        // Shortfall only means something once BOTH stages exist (§L).
        if ($delivery->has_interim && $delivery->has_final) {
            $delivery->shortfall_units = $delivery->computeShortfallUnits();
            $delivery->shortfall_value = $delivery->computeShortfallValue();
        } else {
            $delivery->shortfall_units = 0;
            $delivery->shortfall_value = 0;
        }

        $fcs = PurchaseOrder::query()
            ->where('marketplace', $delivery->marketplace->value)
            ->whereIn('po_number', $delivery->poNumbers())
            ->whereNotNull('ship_to_fc')
            ->distinct()
            ->pluck('ship_to_fc');

        $delivery->fc_code = $fcs->first();
        $delivery->has_fc_conflict = $fcs->count() > 1;

        $delivery->save();

        // The delivery's date is what turnaround is measured to, so changing it - a
        // final arriving, or a corrected date later on - has to move the PO figures too.
        $this->recomputeTurnaround($delivery->marketplace, $delivery->poNumbers());
    }
}
