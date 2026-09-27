<?php

use App\Http\Controllers\Api\PunchController;
use Illuminate\Support\Facades\Route;

Route::post('attendance/nfc/punch', PunchController::class)
    ->middleware('throttle:60,1');
