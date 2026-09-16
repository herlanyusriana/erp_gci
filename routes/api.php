<?php

use App\Http\Controllers\Api\AuthController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:api-login');

// Flutter "Material Tracker" — incoming receiving (JSON)
Route::middleware(['auth:sanctum', 'throttle:api'])->group(function () {
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/incoming/departures', [\App\Http\Controllers\Api\IncomingApiController::class, 'departures']);
    Route::post('/incoming/arrival-items/{arrivalItem}/receive', [\App\Http\Controllers\Api\IncomingApiController::class, 'receive']);

    Route::get('/user', function (Request $request) {
        return $request->user();
    });
});
