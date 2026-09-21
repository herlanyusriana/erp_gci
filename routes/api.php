<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\IncomingApiController;
use App\Http\Controllers\Api\MaterialIssueApiController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:api-login');

// Flutter "Material Tracker" — incoming receiving (JSON)
Route::middleware(['auth:sanctum', 'throttle:api'])->group(function () {
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/incoming/departures', [IncomingApiController::class, 'departures']);
    Route::post('/incoming/arrival-items/{arrivalItem}/receive', [IncomingApiController::class, 'receive']);

    // Issue out to production — release WO lewat scan label.
    Route::get('/work-orders', [MaterialIssueApiController::class, 'workOrders']);
    Route::get('/work-orders/{workOrder}/release-context', [MaterialIssueApiController::class, 'releaseContext']);
    Route::post('/stock-tags/resolve', [MaterialIssueApiController::class, 'resolveTag']);
    Route::post('/machines/resolve', [MaterialIssueApiController::class, 'resolveMachine']);

    // Production result (WIP per proses)
    Route::get('/work-orders/{workOrder}/result-context', [MaterialIssueApiController::class, 'resultContext']);
    Route::post('/work-orders/{workOrder}/results', [MaterialIssueApiController::class, 'storeResult']);
    Route::post('/work-orders/{workOrder}/release', [MaterialIssueApiController::class, 'release']);

    Route::get('/user', function (Request $request) {
        return $request->user();
    });
});
