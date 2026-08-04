<?php

use App\Http\Controllers\CancellationDecisionController;
use App\Http\Controllers\MoneyPinController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\UploadController;
use App\Models\Cancellation;
use App\Models\SourceFile;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/dashboard', function () {
    return view('dashboard', [
        // The §J freshness nudge rides along on the dashboard.
        'overdue' => SourceFile::overdueTypes(),
        // So does the §G queue: a parked cancellation holds real figures still.
        'awaitingDecision' => auth()->user()->can('view-cancelled-items')
            ? Cancellation::needsDecision()->count()
            : 0,
    ]);
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

    /*
     * Cancellations, and the "deliver anyway or pull it" queue (§G). Seeing the queue
     * and answering it are separate rights: Finance watches the chargeback exposure,
     * fulfilment answers the question.
     */
    Route::prefix('cancellations')->name('cancellations.')->group(function () {
        Route::get('/', [CancellationDecisionController::class, 'index'])
            ->middleware('permission:view-cancelled-items')
            ->name('index');

        Route::post('/{cancellation}/decision', [CancellationDecisionController::class, 'decide'])
            ->middleware('permission:decide-cancellations')
            ->name('decide');
    });

    /*
     * The Upload tab (§J). Per-type permissions are enforced inside the controller,
     * because which types a user may upload differs per user.
     */
    Route::prefix('uploads')->name('uploads.')->group(function () {
        Route::get('/', [UploadController::class, 'index'])->name('index');
        Route::post('/', [UploadController::class, 'store'])->name('store');
        // Declared before the {sourceFile} route so "template" is not read as an id.
        Route::get('/template/cancellations', [UploadController::class, 'cancellationTemplate'])
            ->name('cancellation-template');
        Route::get('/{sourceFile}', [UploadController::class, 'show'])->name('show');
        Route::get('/{sourceFile}/download', [UploadController::class, 'download'])->name('download');
    });
});

require __DIR__.'/auth.php';
