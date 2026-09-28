<?php

use App\Http\Controllers\AnalyticsController;
use App\Http\Controllers\AttendanceController;
use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\Auth\RegisteredOrganizationController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DepartmentController;
use App\Http\Controllers\EmployeeController;
use App\Http\Controllers\LeaveController;
use App\Http\Controllers\NfcCardController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\PayslipController;
use App\Http\Controllers\PlatformController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\SalaryController;
use App\Http\Controllers\ScheduleController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\StatisticsController;
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

        Route::get('audit-logs', [AuditLogController::class, 'index'])
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
        // Admin enters a full check-in + check-out pair in one step.
        Route::post('attendance/in-out', [AttendanceController::class, 'storeInOut'])
            ->middleware('permission:attendance.manage')
            ->name('attendance.inout');
        // Re-editable after entry (§24): corrections supersede the original
        // event (never overwritten) and re-derive the affected day(s).
        Route::get('attendance/events/{attendanceEvent}/edit', [AttendanceController::class, 'editEvent'])
            ->middleware('permission:attendance.manage')
            ->name('attendance.events.edit');
        Route::put('attendance/events/{attendanceEvent}', [AttendanceController::class, 'updateEvent'])
            ->middleware('permission:attendance.manage')
            ->name('attendance.events.update');
        Route::delete('attendance/events/{attendanceEvent}', [AttendanceController::class, 'destroyEvent'])
            ->middleware('permission:attendance.manage')
            ->name('attendance.events.destroy');
        // No permission middleware: employees may always open their own record
        // (ownership is enforced in the controller); viewing others requires
        // attendance.view.
        Route::get('attendance/employee/{employee}', [AttendanceController::class, 'employee'])
            ->name('attendance.employee');

        // Phase 3 — compensation: effective-dated salary records (§28).
        Route::get('salary', [SalaryController::class, 'index'])
            ->middleware('permission:salary.view')
            ->name('salary.index');
        // show enforces salary.view OR the employee's own salary.view_own.
        Route::get('salary/{employee}', [SalaryController::class, 'show'])
            ->name('salary.show');
        Route::post('salary/{employee}', [SalaryController::class, 'store'])
            ->middleware('permission:salary.edit')
            ->name('salary.store');

        // Finalized monthly payroll records (§28) with revision history.
        Route::get('salary/{employee}/payslips/{payslip}', [PayslipController::class, 'show'])
            ->name('payslips.show');
        Route::post('salary/{employee}/payslips', [PayslipController::class, 'store'])
            ->middleware('permission:salary.edit')
            ->name('payslips.store');
        Route::put('salary/{employee}/payslips/{payslip}', [PayslipController::class, 'revise'])
            ->middleware('permission:salary.edit')
            ->name('payslips.revise');

        // No permission middleware: employees may always view their own
        // monthly salary statistics (403 inside when no employee profile).
        Route::get('statistics', [StatisticsController::class, 'index'])
            ->name('statistics.index');

        // In-app notification center (§25) — own rows only, no permission.
        Route::get('notifications', [NotificationController::class, 'index'])
            ->name('notifications.index');
        Route::post('notifications/read-all', [NotificationController::class, 'markAllRead'])
            ->name('notifications.readAll');
        // Clicking a notification marks it read (idempotent) and returns.
        Route::get('notifications/{id}', [NotificationController::class, 'markRead'])
            ->name('notifications.read');

        // Phase 3 — reporting (§41) and analytics dashboards.
        Route::get('reports', [ReportController::class, 'attendance'])
            ->middleware('permission:reports.view')
            ->name('reports.attendance');
        Route::get('reports/monthly', [ReportController::class, 'monthly'])
            ->middleware('permission:reports.view')
            ->name('reports.monthly');
        Route::get('reports/salary', [ReportController::class, 'salary'])
            ->middleware('permission:reports.view')
            ->name('reports.salary');
        Route::get('reports/{type}/export', [ReportController::class, 'export'])
            ->middleware('permission:reports.view')
            ->name('reports.export');
        Route::get('analytics', [AnalyticsController::class, 'index'])
            ->middleware('permission:reports.view')
            ->name('analytics.index');

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
        Route::post('nfc-cards/{nfcCard}/replace', [NfcCardController::class, 'replace'])
            ->middleware('permission:nfc.manage')
            ->name('nfc-cards.replace');
        Route::get('nfc-cards/{nfcCard}/history', [NfcCardController::class, 'history'])
            ->middleware('permission:nfc.view')
            ->name('nfc-cards.history');

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
