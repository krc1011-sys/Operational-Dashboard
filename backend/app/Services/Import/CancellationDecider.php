<?php

namespace App\Services\Import;

use App\Enums\CancellationResolution;
use App\Models\Cancellation;
use App\Models\PoLine;
use App\Models\User;

/**
 * The §G cancellation rules, in one place.
 *
 * A cancellation only ever reduces units that are still free - not yet booked into a
 * delivery and not yet shipped. Everything else needs a human answer. Those rules used
 * to live inside the cancellation importer, which meant they ran once, at upload time,
 * and never again. They live here now because they have to run at three moments:
 *
 *   1. when a cancellation file is uploaded;
 *   2. when anything else changes the picture - the PO arrives late, a packing list
 *      books or ships units - which is why the Reconciler calls back into this class;
 *   3. when a person answers the "deliver anyway or pull it" question.
 *
 * Two words used throughout:
 *   HONOURED       - units the cancellation really did take off net accepted.
 *   DELIVERED ANYWAY - units we sent (or will send) despite the cancellation. These are
 *                    our chargeback exposure: Amazon's own email warns that anything
 *                    shipped after the notice can be charged back.
 */
class CancellationDecider
{
    /**
     * What can be netted right now, without asking anybody.
     *
     * @return array{0: CancellationResolution, 1: int, 2: int} resolution, honoured, delivered-anyway
     */
    public function decide(?PoLine $poLine, int $cancelled): array
    {
        if ($cancelled <= 0) {
            return [CancellationResolution::Applied, 0, 0];
        }

        // No PO line yet: store it, net nothing, and revisit when the PO lands. The
        // Reconciler calls back here the moment that happens.
        if ($poLine === null) {
            return [CancellationResolution::Applied, 0, 0];
        }

        if ($cancelled <= $this->freeUnits($poLine)) {
            return [CancellationResolution::Applied, $cancelled, 0];
        }

        /*
         * It would eat into units already promised to a delivery or already gone.
         * Stop and ask (§G), and net NOTHING in the meantime so that no figure moves
         * behind the user's back while they decide.
         */
        return [CancellationResolution::NeedsDecision, 0, 0];
    }

    /**
     * Record a human's answer to a parked cancellation.
     *
     * "Deliver anyway" - we send it regardless. The units stay accepted and count as
     * delivered, so the line can still read 100%, and the line carries a
     * chargeback-exposure warning.
     *
     * "Pull it" - take it back out. Units already SHIPPED cannot be pulled back, so
     * only the rest is honoured and the shipped remainder is recorded as delivered
     * anyway - which correctly raises the chargeback flag for exactly those units.
     * Units that are merely booked into a delivery CAN be pulled: the warehouse leaves
     * them off the truck and the final packing list will simply not contain them.
     */
    public function apply(
        Cancellation $cancellation,
        CancellationResolution $choice,
        ?User $decidedBy = null,
        ?string $note = null
    ): Cancellation {
        $poLine = $cancellation->poLine;
        $cancelled = (int) $cancellation->qty_cancelled;

        [$honoured, $deliveredAnyway] = match ($choice) {
            CancellationResolution::DeliveredAnyway => [0, $cancelled],
            CancellationResolution::PulledBack => [
                $pullable = min($cancelled, $this->pullableUnits($poLine)),
                $cancelled - $pullable,
            ],
            default => [0, 0],
        };

        $cancellation->forceFill([
            'resolution' => $choice,
            'qty_honoured' => $honoured,
            'qty_delivered_anyway' => $deliveredAnyway,
            'resolution_note' => $note,
            'resolved_by' => $decidedBy?->id,
            'resolved_at' => now(),
        ])->save();

        return $cancellation;
    }

    /**
     * Re-run the automatic decision on a cancellation that nobody has answered yet.
     *
     * Returns true if anything changed. Rows a person has already decided, and rows
     * that have already netted, are left exactly as they are - a packing list arriving
     * later must never quietly un-net units that were legitimately cancelled.
     */
    public function reevaluate(Cancellation $cancellation, ?PoLine $poLine): bool
    {
        if (! $cancellation->isPending()) {
            return false;
        }

        [$resolution, $honoured, $deliveredAnyway] = $this->decide($poLine, (int) $cancellation->qty_cancelled);

        if ($cancellation->resolution === $resolution
            && (int) $cancellation->qty_honoured === $honoured
            && (int) $cancellation->qty_delivered_anyway === $deliveredAnyway) {
            return false;
        }

        $cancellation->forceFill([
            'resolution' => $resolution,
            'qty_honoured' => $honoured,
            'qty_delivered_anyway' => $deliveredAnyway,
        ])->save();

        return true;
    }

    /** Units on this line that nothing has claimed yet, so cancelling them is safe. */
    public function freeUnits(?PoLine $poLine): int
    {
        if ($poLine === null) {
            return 0;
        }

        // Booked and shipped overlap - the same units appear on the interim and then
        // the final - so the larger of the two is what is spoken for, not their sum.
        $committed = max((int) $poLine->qty_booked, (int) $poLine->qty_shipped);

        return max(0, (int) $poLine->qty_accepted - $committed);
    }

    /** Units that could still be taken back: everything except what has already shipped. */
    public function pullableUnits(?PoLine $poLine): int
    {
        if ($poLine === null) {
            return 0;
        }

        return max(0, (int) $poLine->qty_accepted - (int) $poLine->qty_shipped);
    }
}
