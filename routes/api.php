<?php

use App\Http\Controllers\Api;
use App\Http\Controllers\Api\PunchController;
use Illuminate\Support\Facades\Route;

// Current clients (legacy path, kept for already-provisioned terminals).
Route::post('attendance/nfc/punch', PunchController::class)
    ->middleware('throttle:60,1');

// Versioned API (§29/§55: all endpoints live under /api/v1).
Route::prefix('v1')->name('api.v1.')->group(function () {
    Route::post('attendance/nfc/punch', PunchController::class)
        ->middleware('throttle:60,1');

    // Token auth (§29): POST login → bearer token, 30-day expiry.
    Route::post('auth/login', [Api\AuthController::class, 'login'])
        ->middleware('throttle:10,1');

    Route::middleware(['auth:sanctum', 'api_organization'])->group(function () {
        Route::post('auth/logout', [Api\AuthController::class, 'logout']);
        Route::get('auth/me', [Api\AuthController::class, 'me']);

        Route::get('employees', [Api\EmployeeController::class, 'index'])
            ->middleware('permission:employees.view');
        Route::get('employees/{employee}', [Api\EmployeeController::class, 'show'])
            ->middleware('permission:employees.view');

        Route::get('departments', [Api\DepartmentController::class, 'index'])
            ->middleware('permission:departments.view');

        Route::get('attendance', [Api\AttendanceController::class, 'index'])
            ->middleware('permission:attendance.view');
        Route::get('attendance/events', [Api\AttendanceController::class, 'events'])
            ->middleware('permission:attendance.view');
        // Own data — no permission middleware.
        Route::get('attendance/mine', [Api\AttendanceController::class, 'mine']);

        Route::get('leave', [Api\LeaveController::class, 'index'])
            ->middleware('permission:leave.view');
        Route::get('leave/mine', [Api\LeaveController::class, 'mine']);
        Route::post('leave', [Api\LeaveController::class, 'store']);

        Route::get('notifications', [Api\NotificationController::class, 'index']);
        Route::post('notifications/read-all', [Api\NotificationController::class, 'markAllRead']);
        Route::post('notifications/{id}/read', [Api\NotificationController::class, 'markRead']);

        Route::get('salary/mine', [Api\SalaryController::class, 'mine']);

        Route::get('audit-logs', [Api\AuditLogController::class, 'index'])
            ->middleware('permission:audit.view');
    });
});
