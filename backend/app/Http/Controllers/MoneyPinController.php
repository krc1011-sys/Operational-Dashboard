<?php

namespace App\Http\Controllers;

use App\Support\MoneyGate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Prompts for and verifies the money/margin PIN (blueprint §S).
 */
class MoneyPinController extends Controller
{
    public function show(): View
    {
        return view('money-pin');
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate(['pin' => ['required', 'string']]);

        $key = 'money-pin:'.$request->user()->id;
        $maxAttempts = max(1, (int) config('operon.money_pin_max_attempts'));

        if (RateLimiter::tooManyAttempts($key, $maxAttempts)) {
            throw ValidationException::withMessages([
                'pin' => 'Too many incorrect attempts. Try again in '
                    .RateLimiter::availableIn($key).' seconds.',
            ]);
        }

        // hash_equals avoids leaking the PIN through response-timing differences.
        if (! hash_equals((string) config('operon.money_pin'), (string) $request->input('pin'))) {
            RateLimiter::hit($key, 300);

            throw ValidationException::withMessages([
                'pin' => 'That PIN is not correct.',
            ]);
        }

        RateLimiter::clear($key);

        MoneyGate::unlock();

        return redirect()->intended(route('money.index'))->with('status',
            'Money figures unlocked. They stay visible while you are working, and lock again '
            .'after '.MoneyGate::timeoutMinutes().' idle minutes or when you log out.');
    }

    /**
     * Manually lock the money screens again without logging out.
     *
     * Returns to where you were rather than to the dashboard: locking is something you do
     * because someone walked up to your desk, and being thrown off the screen as well
     * would be a second, unasked-for thing happening at the worst moment.
     */
    public function destroy(Request $request): RedirectResponse
    {
        MoneyGate::lock();

        return back(fallback: route('dashboard'))
            ->with('status', 'Money figures locked. Enter the PIN again to show them.');
    }
}
