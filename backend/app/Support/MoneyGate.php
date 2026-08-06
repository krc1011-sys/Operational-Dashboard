<?php

namespace App\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;

/**
 * The money gate (§S) — one place that answers "may this person see profit right now?".
 *
 * M7 puts margin figures on screens that are NOT themselves behind the PIN: the PO detail
 * and Products tabs stay open to everyone, and grow extra columns when the PIN is in.
 * That means the question is asked from controllers, from Blade and from middleware, so
 * it lives here rather than being re-derived at each call site with a slightly different
 * permission list.
 *
 * TWO SEPARATE THINGS, and confusing them is the mistake this class exists to prevent:
 *
 *   ORDER VALUE   units x the marketplace's own unit cost. How BIG the order is.
 *                 Open to every role, no PIN - see User::canSeeOrderValue().
 *   MARGIN        cost, profit, margin %. What we MAKE on it.
 *                 `view-margin` AND the PIN, every time.
 *
 * The PIN is unlock-for-the-session: entered once, it stays good until logout or until
 * the session sits idle for `operon.money_pin_timeout` minutes. The window slides on any
 * authenticated request (TouchMoneyPinSession), so it never expires under someone who is
 * still working - only under a screen nobody is watching.
 */
class MoneyGate
{
    /** Where the unlock timestamp lives. Kept as the session's own key since M0. */
    public const SESSION_KEY = 'operon.money_pin_verified_at';

    /** The permission that opens margin, profit and cost-stack figures (§O). */
    public const PERMISSION = 'view-margin';

    /** Is the PIN in, and still inside its idle window? */
    public static function unlocked(): bool
    {
        $at = Session::get(self::SESSION_KEY);

        return $at !== null && self::expiresAt((int) $at)->isFuture();
    }

    /** Record a correct PIN. */
    public static function unlock(): void
    {
        Session::put(self::SESSION_KEY, Carbon::now()->timestamp);
    }

    /** Lock again without logging out. */
    public static function lock(): void
    {
        Session::forget(self::SESSION_KEY);
    }

    /**
     * Slide the idle window forward — but only for a session that is already unlocked.
     *
     * Deliberately never unlocks: touching an expired session must leave it expired, or
     * the timeout would be defeated by the very request that noticed it had lapsed.
     */
    public static function touch(): void
    {
        if (self::unlocked()) {
            Session::put(self::SESSION_KEY, Carbon::now()->timestamp);
        }
    }

    /** When the current unlock lapses, or null if there is nothing to lapse. */
    public static function lapsesAt(): ?Carbon
    {
        $at = Session::get(self::SESSION_KEY);

        return $at === null ? null : self::expiresAt((int) $at);
    }

    /** Whole minutes left on the unlock, rounded up. Null when locked. */
    public static function minutesRemaining(): ?int
    {
        if (! self::unlocked()) {
            return null;
        }

        return max(1, (int) ceil(Carbon::now()->diffInSeconds(self::lapsesAt(), false) / 60));
    }

    /** How long an unlock lasts, in minutes. */
    public static function timeoutMinutes(): int
    {
        return max(1, (int) config('operon.money_pin_timeout'));
    }

    /** Does this user hold the margin permission at all, PIN or no PIN? */
    public static function hasMarginPermission(): bool
    {
        return (bool) Auth::user()?->can(self::PERMISSION);
    }

    /**
     * THE question. Permission and PIN, never one without the other.
     *
     * A correct PIN cannot conjure a permission the role does not hold, and a permission
     * cannot skip the PIN - §S asks for both, and an unattended screen is exactly the
     * case the second factor is for.
     */
    public static function canSeeMargin(): bool
    {
        return self::hasMarginPermission() && self::unlocked();
    }

    /**
     * Should we offer an unlock prompt? True only for someone the PIN would actually
     * help. Everyone else is shown nothing, rather than a locked door they can never
     * open - a padlock you have no key to is noise, not security.
     */
    public static function needsUnlock(): bool
    {
        return self::hasMarginPermission() && ! self::unlocked();
    }

    private static function expiresAt(int $timestamp): Carbon
    {
        return Carbon::createFromTimestamp($timestamp)->addMinutes(self::timeoutMinutes());
    }
}
