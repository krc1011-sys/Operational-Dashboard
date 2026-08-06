<?php

namespace App\Http\Middleware;

use App\Support\MoneyGate;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keeps an unlocked money session alive while its owner is working (§S, M7).
 *
 * The PIN is unlock-for-the-session: entered once, it stays in until logout or until the
 * session goes idle. Before M7 the window only slid on the handful of routes carrying the
 * `money.pin` middleware, which made it a timeout on MONEY-SCREEN activity rather than on
 * activity — someone who unlocked, then spent twenty minutes on Fulfilment, came back to
 * PO detail and found the money columns silently gone, with nothing on screen explaining
 * why. That reads as a bug, not as security.
 *
 * So the window now slides on any authenticated request. What the timeout protects
 * against is an unattended screen, and a person clicking around the app is not that.
 *
 * It never unlocks anything: MoneyGate::touch() is a no-op on a session that is locked or
 * already lapsed, so the only thing this can do is postpone a lock, never skip one.
 */
class TouchMoneyPinSession
{
    public function handle(Request $request, Closure $next): Response
    {
        MoneyGate::touch();

        return $next($request);
    }
}
