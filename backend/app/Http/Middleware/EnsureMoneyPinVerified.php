<?php

namespace App\Http\Middleware;

use App\Support\MoneyGate;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Second factor for money screens (blueprint §S: "money = ADMIN ONLY, behind PIN/password").
 *
 * Role permissions decide WHO may see cost/price/margin. This middleware adds the PIN
 * confirmation on top for routes that exist ONLY to show money — the Profitability
 * section, and every master-grid write. Apply it alongside the permission middleware:
 *
 *     Route::middleware(['auth', 'permission:view-margin', 'money.pin'])
 *
 * It is NOT how the inline money columns added at M7 are protected. Those live on screens
 * open to roles holding no money permission at all, so putting the PIN on their routes
 * would bounce people off a screen §O grants them. They ask MoneyGate and render fewer
 * columns instead.
 *
 * The state itself — unlock, idle window, lock — belongs to MoneyGate. This class is the
 * door; MoneyGate is the lock.
 */
class EnsureMoneyPinVerified
{
    /** Kept as the canonical name for existing call sites; the value lives in MoneyGate. */
    public const SESSION_KEY = MoneyGate::SESSION_KEY;

    public function handle(Request $request, Closure $next): Response
    {
        if (MoneyGate::unlocked()) {
            // Sliding window: activity on a money screen keeps the PIN alive.
            MoneyGate::touch();

            return $next($request);
        }

        // Remember where they were headed so we can send them back after the PIN.
        $request->session()->put('url.intended', $request->fullUrl());

        return redirect()->route('money-pin.prompt');
    }

    /** Has this session entered the PIN recently enough? */
    public static function verified(?Request $request = null): bool
    {
        return MoneyGate::unlocked();
    }
}
