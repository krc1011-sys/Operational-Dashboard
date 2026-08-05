<?php

use App\Http\Controllers\CancellationDecisionController;
use App\Http\Controllers\DeliveriesController;
use App\Http\Controllers\FulfilmentController;
use App\Http\Controllers\MasterController;
use App\Http\Controllers\MoneyPinController;
use App\Http\Controllers\OverviewController;
use App\Http\Controllers\PoLookupController;
use App\Http\Controllers\ProductsController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ShipmentsController;
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
     * The core screens (§M, M5).
     *
     * Each answers to GET and POST on the same URL: the filter bar POSTs, because a
     * pasted list of ASINs or an uploaded file will not fit in a query string, and the
     * controller turns it straight back into a plain shareable GET link. That way every
     * screen stays bookmarkable, pageable and exportable with the filters intact.
     */
    Route::match(['get', 'post'], '/overview', [OverviewController::class, 'index'])
        ->middleware('permission:view-overview')->name('overview.index');

    Route::match(['get', 'post'], '/po-lookup', [PoLookupController::class, 'index'])
        ->middleware('permission:view-po-status')->name('po-lookup.index');
    Route::get('/po-lookup/{poNumber}', [PoLookupController::class, 'show'])
        ->middleware('permission:view-po-status')->name('po-lookup.show');

    /*
     * Fulfilment, with the old Pending tab merged in as its "Not booked" status
     * (DESIGN_BRIEF §8).
     *
     * EITHER permission opens it, deliberately. §O gives Sales `view-pending` without
     * `view-fulfillment`, so requiring only the latter would have silently removed a
     * screen Sales had. The controller then holds a pending-only user to the not-booked
     * view, which is exactly what their old tab showed.
     */
    Route::match(['get', 'post'], '/fulfilment', [FulfilmentController::class, 'index'])
        ->middleware('permission:view-fulfillment|view-pending')->name('fulfilment.index');

    // The old Pending URL keeps working and lands on the same rows it always did.
    Route::get('/pending', fn () => redirect()->route('fulfilment.index', ['view' => 'outstanding']))
        ->middleware('permission:view-fulfillment|view-pending')->name('pending.index');

    /*
     * Deliveries — Shipments and Committed merged behind a Booked/Shipped toggle
     * (DESIGN_BRIEF §8).
     *
     * EITHER permission opens it. §O gives Sales `view-committed-deliveries` without
     * `view-shipments` and Warehouse the reverse, so requiring both - or either one
     * alone - would have taken a screen from somebody. The controller then offers only
     * the halves of the toggle a user actually holds.
     */
    Route::match(['get', 'post'], '/deliveries', [DeliveriesController::class, 'index'])
        ->middleware('permission:view-shipments|view-committed-deliveries')->name('deliveries.index');
    Route::get('/deliveries/{delivery}', [DeliveriesController::class, 'show'])
        ->middleware('permission:view-shipments')->name('deliveries.show');
    Route::patch('/deliveries/{delivery}/date', [ShipmentsController::class, 'updateDate'])
        ->middleware('permission:manage-fulfillment')->name('deliveries.date');

    // The old URLs still land where they always did.
    Route::get('/shipments', fn () => redirect()->route('deliveries.index', ['view' => 'shipped']))
        ->middleware('permission:view-shipments')->name('shipments.index');
    Route::get('/shipments/{delivery}', fn ($delivery) => redirect()->route('deliveries.show', $delivery))
        ->middleware('permission:view-shipments')->name('shipments.show');
    Route::get('/committed-deliveries', fn () => redirect()->route('deliveries.index', ['view' => 'booked']))
        ->middleware('permission:view-committed-deliveries')->name('committed.index');

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
     * Products — the SKU analytics home (DESIGN_BRIEF §8). Brand and category rollups
     * and the labelled quadrant live here rather than being scattered across screens.
     */
    Route::match(['get', 'post'], '/products', [ProductsController::class, 'index'])
        ->middleware('permission:view-analytics|view-master')->name('products.index');

    /*
     * The master catalog and its unit economics (§S, M6).
     *
     * Viewing the catalog needs `view-master`, which §O gives to most roles - it is the
     * product lookup, and it holds no money. Everything that touches money or changes a
     * row additionally needs `manage-master` AND the PIN, which is §S's "Admin-only +
     * PIN". The money columns are withheld inside the controller rather than by putting
     * the PIN on the index route, because bouncing Warehouse off a screen §O grants them
     * would be the wrong protection in the wrong place.
     */
    Route::prefix('master')->name('master.')->group(function () {
        Route::get('/', [MasterController::class, 'index'])
            ->middleware('permission:view-master')->name('index');

        Route::middleware(['permission:manage-master', 'money.pin'])->group(function () {
            Route::post('/', [MasterController::class, 'store'])->name('store');
            Route::patch('/products/{product}', [MasterController::class, 'updateProduct'])->name('products.update');
            Route::patch('/economics/{economics}', [MasterController::class, 'updateEconomics'])->name('economics.update');
            Route::delete('/products/{product}', [MasterController::class, 'destroy'])->name('products.destroy');
            Route::post('/anomalies/{anomaly}/resolve', [MasterController::class, 'resolveAnomaly'])->name('anomalies.resolve');
        });
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
