<?php

use App\Http\Controllers\Api\PunchController;
use Illuminate\Support\Facades\Route;

// Current clients (legacy path, kept for already-provisioned terminals).
Route::post('attendance/nfc/punch', PunchController::class)
    ->middleware('throttle:60,1');

// Versioned endpoint (§55: all endpoints live under /api/v1).
Route::prefix('v1')->group(function () {
    Route::post('attendance/nfc/punch', PunchController::class)
        ->middleware('throttle:60,1');
});
