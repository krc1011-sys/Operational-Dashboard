<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\Response;

/**
 * Second factor for money screens (blueprint §S: "money = ADMIN ONLY, behind PIN/password").
 *
 * Role permissions decide WHO may see cost/price/margin. This middleware adds a
 * short-lived PIN confirmation on top, so an unattended logged-in session cannot
 * expose P&L. Apply it alongside the permission middleware, e.g.
 *
 *     Route::middleware(['auth', 'permission:view-margin', 'money.pin'])
 *
 * The verification is stored in the session and expires after
 * config('operon.money_pin_timeout') minutes.
 */
class EnsureMoneyPinVerified
{
    public const SESSION_KEY = 'operon.money_pin_verified_at';

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->verified($request)) {
            // Sliding window: activity on a money screen keeps the PIN alive.
            $request->session()->put(self::SESSION_KEY, Carbon::now()->timestamp);

            return $next($request);
        }

        // Remember where they were headed so we can send them back after the PIN.
        $request->session()->put('url.intended', $request->fullUrl());

        return redirect()->route('money-pin.prompt');
    }

    /** Has this session entered the PIN recently enough? */
    public static function verified(Request $request): bool
    {
        $verifiedAt = $request->session()->get(self::SESSION_KEY);

        if (! $verifiedAt) {
            return false;
        }

        $timeout = max(1, (int) config('operon.money_pin_timeout'));

        return Carbon::createFromTimestamp($verifiedAt)->addMinutes($timeout)->isFuture();
    }
}
