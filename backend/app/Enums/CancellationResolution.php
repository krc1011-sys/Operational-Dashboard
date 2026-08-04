<?php

namespace App\Enums;

/**
 * How a cancellation was handled (blueprint §G).
 *
 * The base rule is that a cancellation reduces only not-yet-shipped units. When it
 * would claw back units ALREADY BOOKED into a delivery or ALREADY SHIPPED, the system
 * stops and asks, and the answer is recorded here.
 */
enum CancellationResolution: string
{
    /** Applied automatically: the units were not booked or shipped, so netting was safe. */
    case Applied = 'applied';

    /** Needs a human decision: it would claw back booked/shipped units. */
    case NeedsDecision = 'needs_decision';

    /** "Pull it" — remove from the delivery / reduce as normal. */
    case PulledBack = 'pulled_back';

    /**
     * "Deliver anyway" — disregard the cancellation for those units. They stay accepted
     * and count as delivered, but the line carries a chargeback-exposure warning flag.
     */
    case DeliveredAnyway = 'delivered_anyway';

    public function label(): string
    {
        return match ($this) {
            self::Applied => 'Applied',
            self::NeedsDecision => 'Needs your decision',
            self::PulledBack => 'Pulled back',
            self::DeliveredAnyway => 'Delivered anyway',
        };
    }

    /** Does this resolution reduce net-accepted units? */
    public function netsAccepted(): bool
    {
        return match ($this) {
            self::Applied, self::PulledBack => true,
            self::NeedsDecision, self::DeliveredAnyway => false,
        };
    }

    /** Does this resolution expose us to an Amazon chargeback? (§G) */
    public function isChargebackExposure(): bool
    {
        return $this === self::DeliveredAnyway;
    }
}
