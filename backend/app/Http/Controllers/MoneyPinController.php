<?php

namespace App\Http\Controllers;

use App\Http\Middleware\EnsureMoneyPinVerified;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
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

        $request->session()->put(EnsureMoneyPinVerified::SESSION_KEY, Carbon::now()->timestamp);

        return redirect()->intended(route('dashboard'));
    }

    /** Manually lock the money screens again without logging out. */
    public function destroy(Request $request): RedirectResponse
    {
        $request->session()->forget(EnsureMoneyPinVerified::SESSION_KEY);

        return redirect()->route('dashboard')->with('status', 'Money screens locked.');
    }
}
