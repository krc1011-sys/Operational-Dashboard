<?php

use App\Http\Controllers\MoneyPinController;
use App\Http\Controllers\ProfileController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/dashboard', function () {
    return view('dashboard');
})->middleware(['auth', 'verified'])->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    // Money/margin PIN gate (blueprint §S).
    Route::get('/money-pin', [MoneyPinController::class, 'show'])->name('money-pin.prompt');
    Route::post('/money-pin', [MoneyPinController::class, 'store'])->name('money-pin.store');
    Route::post('/money-pin/lock', [MoneyPinController::class, 'destroy'])->name('money-pin.lock');

    /*
     * Placeholder for the M7 money views. It exists now so the two-layer guard
     * (role permission + PIN) is wired and testable from M0 onwards.
     */
    Route::get('/money', fn () => view('money-placeholder'))
        ->middleware(['permission:view-margin', 'money.pin'])
        ->name('money.index');
});

require __DIR__.'/auth.php';
