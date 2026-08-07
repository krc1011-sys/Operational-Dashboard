<?php

namespace App\Services\Analytics;

/**
 * How fast one SKU sells on one channel, and — just as importantly — HOW WE KNOW (M9).
 *
 * Days of cover is stock ÷ run rate, so the run rate is the number every reorder
 * decision turns on. The three channels hand us three genuinely different qualities of
 * answer, and flattening them into one bare "units/day" would hide exactly the thing a
 * person needs to judge it by:
 *
 *   NOON      Noon publishes L7_DRR — their OWN 7-day daily run rate, computed from their
 *             complete order book. Nothing we derive can beat it, so it wins.
 *   DFS       Dated order lines, so a real trailing average is derivable. L7 when the SKU
 *             has moved in the last week, L30 when it has not — a week of zeroes on a
 *             slow SKU is noise, not a stop.
 *   AMAZON    The sell-out report is ONE AGGREGATED ROW per ASIN over the whole window.
 *             There is no daily detail in the file, so the only honest answer is
 *             units ÷ window days: a PERIOD AVERAGE. It is not a current rate and it
 *             cannot see a trend, and `isPeriodAverage` is true so every screen can say
 *             so rather than presenting it as something it is not.
 *
 * A rate of zero and a rate of null are different: zero means it sold nothing over a
 * window we can measure (that is the dead-stock signal), null means we have no basis at
 * all. Neither is ever turned into the other.
 */
class RunRate
{
    private function __construct(
        /** Units per day, or null when there is no basis to compute one. */
        public readonly ?float $perDay,
        /** Plain-English provenance, shown on screen beside the number. */
        public readonly string $basis,
        /** How many days the rate was measured over. */
        public readonly ?int $windowDays,
        /** True when this is an average over a whole window, not a current rate. */
        public readonly bool $isPeriodAverage = false,
        /** True when the channel gave us the figure rather than us deriving it. */
        public readonly bool $isStated = false,
    ) {}

    /** Noon's own L7_DRR. */
    public static function stated(float $perDay, string $basis, int $windowDays): self
    {
        return new self(round($perDay, 4), $basis, $windowDays, false, true);
    }

    /** A trailing average we worked out from dated rows. */
    public static function derived(int $units, int $days, string $basis): self
    {
        $days = max(1, $days);

        return new self(round($units / $days, 4), $basis, $days);
    }

    /**
     * Units over a whole reporting window, with no daily detail behind it.
     * Flagged, because a period average reads like a current rate and is not one.
     */
    public static function periodAverage(int $units, int $days, string $basis): self
    {
        $days = max(1, $days);

        return new self(round($units / $days, 4), $basis, $days, true);
    }

    public static function unknown(string $basis = 'no sell-out data'): self
    {
        return new self(null, $basis, null);
    }

    public function isKnown(): bool
    {
        return $this->perDay !== null;
    }

    /** Sold nothing at all over a window we could measure — the dead-stock signal. */
    public function isDead(): bool
    {
        return $this->perDay !== null && $this->perDay <= 0;
    }

    /**
     * Days of cover for a given stock level.
     *
     * Null when there is no rate, and null when the rate is zero — NOT infinity and not
     * some large sentinel. A SKU selling nothing does not have "999 days of cover", it
     * has an undefined cover and a dead-stock problem, and the watchlists catch it by
     * that route instead of by an invented number that would sort to the top of every
     * table.
     */
    public function coverDays(?int $soh): ?float
    {
        if ($soh === null || $this->perDay === null || $this->perDay <= 0) {
            return null;
        }

        return round($soh / $this->perDay, 1);
    }
}
