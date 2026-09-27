<?php

use App\Http\Controllers\AttendanceController;
use App\Http\Controllers\Auth\RegisteredOrganizationController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DepartmentController;
use App\Http\Controllers\EmployeeController;
use App\Http\Controllers\LeaveController;
use App\Http\Controllers\NfcCardController;
use App\Http\Controllers\PlatformController;
use App\Http\Controllers\ScheduleController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\TerminalController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome');

// Registration creates an organization (replaces default Breeze register).
Route::middleware('guest')->group(function () {
    Route::get('register', [RegisteredOrganizationController::class, 'create'])
        ->name('register');
    Route::post('register', [RegisteredOrganizationController::class, 'store'])
        ->middleware('throttle:10,1');
});

Route::middleware(['auth', 'verified', 'organization', 'company'])
    ->group(function () {
        Route::get('dashboard', function () {
            $user = auth()->user();

            if ($user->canPermission('dashboard.admin')) {
                return redirect()->route('dashboard.admin');
            }

            return redirect()->route('dashboard.employee');
        })->name('dashboard');

        Route::get('dashboard/admin', [DashboardController::class, 'admin'])
            ->name('dashboard.admin');
        Route::get('dashboard/employee', [DashboardController::class, 'employee'])
            ->name('dashboard.employee');

        Route::resource('departments', DepartmentController::class)
            ->except(['show']);

        Route::resource('employees', EmployeeController::class);

        Route::resource('users', UserController::class)
            ->except(['show']);

        Route::get('audit-logs', [\App\Http\Controllers\AuditLogController::class, 'index'])
            ->middleware('permission:audit.view')
            ->name('audit-logs.index');

        Route::get('settings', [SettingsController::class, 'edit'])
            ->middleware('permission:settings.manage')
            ->name('settings.edit');
        Route::put('settings', [SettingsController::class, 'update'])
            ->middleware('permission:settings.manage')
            ->name('settings.update');
        Route::get('settings/schedule', [ScheduleController::class, 'edit'])
            ->middleware('permission:settings.manage')
            ->name('schedule.edit');
        Route::put('settings/schedule', [ScheduleController::class, 'update'])
            ->middleware('permission:settings.manage')
            ->name('schedule.update');

        Route::get('attendance', [AttendanceController::class, 'index'])
            ->middleware('permission:attendance.view')
            ->name('attendance.index');
        Route::get('attendance/correct', [AttendanceController::class, 'create'])
            ->middleware('permission:attendance.manage')
            ->name('attendance.create');
        Route::post('attendance/correct', [AttendanceController::class, 'store'])
            ->middleware('permission:attendance.manage')
            ->name('attendance.store');
        Route::get('attendance/employee/{employee}', [AttendanceController::class, 'employee'])
            ->middleware('permission:attendance.view')
            ->name('attendance.employee');

        Route::get('leave', [LeaveController::class, 'index'])
            ->middleware('permission:leave.view')
            ->name('leave.index');
        Route::get('leave/create', [LeaveController::class, 'create'])
            ->name('leave.create');
        Route::post('leave', [LeaveController::class, 'store'])
            ->name('leave.store');
        Route::post('leave/{leaveRequest}/approve', [LeaveController::class, 'approve'])
            ->middleware('permission:leave.approve')
            ->name('leave.approve');
        Route::post('leave/{leaveRequest}/reject', [LeaveController::class, 'reject'])
            ->middleware('permission:leave.approve')
            ->name('leave.reject');
        Route::post('leave/{leaveRequest}/cancel', [LeaveController::class, 'cancel'])
            ->name('leave.cancel');

        Route::get('nfc-cards', [NfcCardController::class, 'index'])
            ->middleware('permission:nfc.view')
            ->name('nfc-cards.index');
        Route::post('nfc-cards', [NfcCardController::class, 'store'])
            ->middleware('permission:nfc.manage')
            ->name('nfc-cards.store');
        Route::post('nfc-cards/{nfcCard}/assign', [NfcCardController::class, 'assign'])
            ->middleware('permission:nfc.manage')
            ->name('nfc-cards.assign');
        Route::post('nfc-cards/{nfcCard}/unassign', [NfcCardController::class, 'unassign'])
            ->middleware('permission:nfc.manage')
            ->name('nfc-cards.unassign');
        Route::post('nfc-cards/{nfcCard}/block', [NfcCardController::class, 'block'])
            ->middleware('permission:nfc.manage')
            ->name('nfc-cards.block');
        Route::post('nfc-cards/{nfcCard}/revoke', [NfcCardController::class, 'revoke'])
            ->middleware('permission:nfc.manage')
            ->name('nfc-cards.revoke');

        Route::get('terminals', [TerminalController::class, 'index'])
            ->middleware('permission:attendance.terminal')
            ->name('terminals.index');
        Route::post('terminals', [TerminalController::class, 'store'])
            ->middleware('permission:attendance.terminal')
            ->name('terminals.store');
        Route::delete('terminals/{terminal}', [TerminalController::class, 'destroy'])
            ->middleware('permission:attendance.terminal')
            ->name('terminals.destroy');

        Route::get('profile', fn () => view('profile'))->name('profile');
    });

// Platform (super admin) area
Route::prefix('platform')
    ->middleware(['auth', 'verified', 'platform_admin'])
    ->name('platform.')
    ->group(function () {
        Route::get('organizations', [PlatformController::class, 'organizations'])
            ->name('organizations');
        Route::patch('organizations/{organization}/status', [PlatformController::class, 'toggleStatus'])
            ->name('organizations.status');
    });

require __DIR__.'/auth.php';
