<?php

namespace App\Enums;

/**
 * The two stages of a delivery's packing list (blueprint §K).
 *
 * Interim = what we PLANNED to ship  → drives "Booked / Scheduled".
 * Final   = what we ACTUALLY shipped → drives "Dispatched / Shipped" and fill rate.
 *
 * Shortfall is the difference between the two (§L).
 */
enum Stage: string
{
    case Interim = 'interim';
    case Final = 'final';

    public function label(): string
    {
        return match ($this) {
            self::Interim => 'Interim',
            self::Final => 'Final',
        };
    }

    /** The per-line status word this stage produces (§F). */
    public function statusWord(): string
    {
        return match ($this) {
            self::Interim => 'Booked',
            self::Final => 'Shipped',
        };
    }
}
