<?php

use App\Http\Controllers\Api;
use App\Http\Controllers\Api\PunchController;
use App\Http\Controllers\ScimController;
use App\Http\Controllers\StripeWebhookController;
use Illuminate\Support\Facades\Route;

// Current clients (legacy path, kept for already-provisioned terminals).
Route::post('attendance/nfc/punch', PunchController::class)
    ->middleware('throttle:60,1');

// Stripe Checkout webhooks (signature-verified, no session auth).
Route::post('stripe/webhook', StripeWebhookController::class)
    ->middleware('throttle:30,1');

// SCIM 2.0 provisioning (Phase 8): bearer SCIM token auth, org-scoped.
Route::prefix('scim/v2')->middleware(['auth:scim', 'api_organization', 'throttle:120,1'])->group(function () {
    Route::get('ServiceProviderConfig', [ScimController::class, 'serviceProviderConfig']);
    Route::get('ResourceTypes', [ScimController::class, 'resourceTypes']);

    Route::get('Users', [ScimController::class, 'indexUsers'])
        ->middleware('permission:users.view');
    Route::post('Users', [ScimController::class, 'storeUser'])
        ->middleware('permission:users.manage');
    Route::get('Users/{id}', [ScimController::class, 'showUser'])
        ->middleware('permission:users.view');
    Route::put('Users/{id}', [ScimController::class, 'updateUser'])
        ->middleware('permission:users.manage');
    Route::patch('Users/{id}', [ScimController::class, 'updateUser'])
        ->middleware('permission:users.manage');
    Route::delete('Users/{id}', [ScimController::class, 'destroyUser'])
        ->middleware('permission:users.manage');

    Route::get('Groups', [ScimController::class, 'indexGroups'])
        ->middleware('permission:departments.view');
    Route::post('Groups', [ScimController::class, 'storeGroup'])
        ->middleware('permission:departments.manage');
    Route::get('Groups/{id}', [ScimController::class, 'showGroup'])
        ->middleware('permission:departments.view');
    Route::put('Groups/{id}', [ScimController::class, 'updateGroup'])
        ->middleware('permission:departments.manage');
    Route::patch('Groups/{id}', [ScimController::class, 'updateGroup'])
        ->middleware('permission:departments.manage');
    Route::delete('Groups/{id}', [ScimController::class, 'destroyGroup'])
        ->middleware('permission:departments.manage');
});

// Versioned API (§29/§55: all endpoints live under /api/v1).
Route::prefix('v1')->name('api.v1.')->group(function () {
    Route::post('attendance/nfc/punch', PunchController::class)
        ->middleware('throttle:60,1');

    // Token auth (§29): POST login → bearer token, 30-day expiry.
    Route::post('auth/login', [Api\AuthController::class, 'login'])
        ->middleware('throttle:10,1');

    Route::middleware(['auth:sanctum,apikey', 'api_organization'])->group(function () {
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
        // Own data — no permission middleware (employee-owned resources only).
        Route::get('attendance/mine', [Api\AttendanceController::class, 'mine'])
            ->middleware('company');
        Route::get('leave/mine', [Api\LeaveController::class, 'mine'])
            ->middleware('company');
        Route::get('salary/mine', [Api\SalaryController::class, 'mine'])
            ->middleware('company');

        Route::get('leave', [Api\LeaveController::class, 'index'])
            ->middleware('permission:leave.view');
        Route::post('leave', [Api\LeaveController::class, 'store'])
            ->middleware('company');

        Route::get('notifications', [Api\NotificationController::class, 'index']);
        Route::post('notifications/read-all', [Api\NotificationController::class, 'markAllRead']);
        Route::post('notifications/{id}/read', [Api\NotificationController::class, 'markRead']);

        Route::get('audit-logs', [Api\AuditLogController::class, 'index'])
            ->middleware('permission:audit.view');
    });
});
