<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\IncomingApiController;
use App\Http\Controllers\Api\LocationApiController;
use App\Http\Controllers\Api\MaterialBoardApiController;
use App\Http\Controllers\Api\MaterialIssueApiController;
use App\Http\Controllers\Api\ProductionReceiptController;
use App\Http\Controllers\Api\WipBoardApiController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:api-login');

// Flutter "Material Tracker" — incoming receiving (JSON)
Route::middleware(['auth:sanctum', 'throttle:api'])->group(function () {
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    // Gudang: penerimaan material. Dijaga di level route karena `Gate::authorize`
    // di controller hanya memakai IncomingArrivalPolicy, sedangkan peran
    // produksi juga memegang `incoming.view`/`receive.view` (keduanya ikut
    // `$viewAll` di RolesAndPermissionsSeeder) sehingga lolos `viewAny`.
    // Daftar permission di bawah sama dengan aturan policy `receive` +
    // `create`: warehouse, purchasing & QC tetap boleh, produksi ditolak.
    Route::get('/incoming/departures', [IncomingApiController::class, 'departures'])
        ->middleware('permission:receive.create,incoming.update');
    Route::post('/incoming/arrival-items/{arrivalItem}/receive', [IncomingApiController::class, 'receive'])
        ->middleware('permission:receive.create,incoming.update');

    // Papan material harian (berbasis material, bukan WO).
    Route::get('/material-board', [MaterialBoardApiController::class, 'index']);
    Route::get('/material-board/{part}/details', [MaterialBoardApiController::class, 'details']);

    // Issue out to production — release WO lewat scan label.
    Route::get('/work-orders', [MaterialIssueApiController::class, 'workOrders']);
    Route::get('/work-orders/{workOrder}/release-context', [MaterialIssueApiController::class, 'releaseContext']);
    Route::post('/stock-tags/resolve', [MaterialIssueApiController::class, 'resolveTag']);
    Route::post('/machines/resolve', [MaterialIssueApiController::class, 'resolveMachine']);
    Route::post('/locations/resolve', [LocationApiController::class, 'resolve']);

    // Production result (WIP per proses)
    Route::get('/work-orders/{workOrder}/result-context', [MaterialIssueApiController::class, 'resultContext']);
    Route::post('/work-orders/{workOrder}/results', [MaterialIssueApiController::class, 'storeResult']);
    Route::post('/work-orders/{workOrder}/release', [MaterialIssueApiController::class, 'release']);
    Route::post('/receipts/confirm', [ProductionReceiptController::class, 'confirm']);
    Route::post('/receipts/resolve', [ProductionReceiptController::class, 'resolve']);
    Route::get('/receipts', [ProductionReceiptController::class, 'index']);
    Route::get('/machines/{machine}/production-session', [MaterialIssueApiController::class, 'productionSession']);
    Route::post('/machines/{machine}/downtimes', [MaterialIssueApiController::class, 'storeDowntime']);
    Route::delete('/machines/{machine}/downtimes/{downtime}', [MaterialIssueApiController::class, 'destroyDowntime']);
    Route::get('/wip-candidates', [MaterialIssueApiController::class, 'wipCandidates']);
    Route::get('/wip-board', [WipBoardApiController::class, 'index']);

    Route::get('/user', function (Request $request) {
        return $request->user();
    });
});
