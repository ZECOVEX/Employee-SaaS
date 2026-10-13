<?php

declare(strict_types=1);

use App\Http\Controllers\AnalyticsController;
use App\Http\Controllers\ApiKeyController;
use App\Http\Controllers\AttendanceController;
use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\Auth\RegisteredOrganizationController;
use App\Http\Controllers\BillingController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DepartmentController;
use App\Http\Controllers\EmployeeController;
use App\Http\Controllers\LeaveController;
use App\Http\Controllers\LiveAttendanceController;
use App\Http\Controllers\MailSettingsController;
use App\Http\Controllers\NfcCardController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\OvertimeController;
use App\Http\Controllers\OvertimePolicyController;
use App\Http\Controllers\PayslipController;
use App\Http\Controllers\PlatformController;
use App\Http\Controllers\PositionController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\RolePermissionController;
use App\Http\Controllers\SalaryController;
use App\Http\Controllers\ScheduleController;
use App\Http\Controllers\ScoringSettingsController;
use App\Http\Controllers\SearchController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\SsoLoginController;
use App\Http\Controllers\SsoSettingsController;
use App\Http\Controllers\StatisticsController;
use App\Http\Controllers\SupportTicketController;
use App\Http\Controllers\TerminalController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\WebhookController;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome');

// Phase 8 — OIDC SSO (guest): redirect to the IdP and complete the callback.
// The callback route must precede the {organization} wildcard.
Route::middleware('guest')->group(function () {
    Route::get('sso/callback', [SsoLoginController::class, 'callback'])
        ->middleware('throttle:30,1')
        ->name('sso.callback');
    Route::get('sso/{organization}', [SsoLoginController::class, 'redirectToProvider'])
        ->name('sso.login');
});

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

        // Positions (designations) — `positions.manage` enforced in the
        // controller; the dropdown on the employee form needs no gate.
        Route::resource('positions', PositionController::class)
            ->except(['show']);

        Route::resource('employees', EmployeeController::class);

        Route::resource('users', UserController::class)
            ->except(['show']);

        // Admin/manager issues an 8-char temporary password (§ password
        // recovery); `users.manage` enforced in the controller.
        Route::post('users/{user}/reset-password', [UserController::class, 'resetPassword'])
            ->name('users.reset_password');

        // §43 — admin-controlled deactivation/reactivation + data export.
        Route::post('users/{user}/deactivate', [UserController::class, 'deactivate'])
            ->name('users.deactivate');
        Route::post('users/{user}/activate', [UserController::class, 'activate'])
            ->name('users.activate');
        Route::get('users/{user}/export', [UserController::class, 'export'])
            ->name('users.export');

        // Password-recovery support tickets — filed by guests from the
        // forgot-password page; Gate::support.view = admins + managers +
        // department managers.
        Route::get('support', [SupportTicketController::class, 'index'])
            ->name('support.index');
        Route::get('support/{ticket}', [SupportTicketController::class, 'show'])
            ->name('support.show');
        Route::post('support/{ticket}/reply', [SupportTicketController::class, 'reply'])
            ->name('support.reply');
        Route::post('support/{ticket}/reset-password', [SupportTicketController::class, 'resetPassword'])
            ->name('support.reset_password');
        Route::post('support/{ticket}/resolve', [SupportTicketController::class, 'resolve'])
            ->name('support.resolve');

        // Global search - per-section RBAC is enforced in the controller.
        Route::get('search', [SearchController::class, 'index'])
            ->name('search.index');

        Route::get('audit-logs', [AuditLogController::class, 'index'])
            ->middleware('permission:audit.view')
            ->name('audit-logs.index');
        // §71 — login history (GeoIP-resolved attempts), filterable.
        Route::get('audit-logs/logins', [AuditLogController::class, 'logins'])
            ->middleware('permission:audit.view')
            ->name('audit-logs.logins');
        // Phase 8 — advanced audit: CSV export honouring the same filters.
        Route::get('audit-logs/export', [AuditLogController::class, 'export'])
            ->middleware('permission:audit.view')
            ->name('audit-logs.export');

        Route::get('settings', [SettingsController::class, 'edit'])
            ->middleware('permission:settings.manage')
            ->name('settings.edit');
        Route::put('settings', [SettingsController::class, 'update'])
            ->middleware('permission:settings.manage')
            ->name('settings.update');

        // Phase 8 — API keys + SCIM tokens (settings.manage).
        Route::get('settings/api-keys', [ApiKeyController::class, 'index'])
            ->name('api-keys.index');
        Route::post('settings/api-keys', [ApiKeyController::class, 'store'])
            ->name('api-keys.store');
        Route::delete('settings/api-keys/{apiKey}', [ApiKeyController::class, 'destroy'])
            ->name('api-keys.destroy');
        Route::post('settings/scim-tokens', [ApiKeyController::class, 'storeScimToken'])
            ->name('scim-tokens.store');
        Route::delete('settings/scim-tokens/{scimToken}', [ApiKeyController::class, 'destroyScimToken'])
            ->name('scim-tokens.destroy');

        // Phase 8 — webhooks (settings.manage).
        Route::get('settings/webhooks', [WebhookController::class, 'index'])
            ->name('webhooks.index');
        Route::post('settings/webhooks', [WebhookController::class, 'store'])
            ->name('webhooks.store');
        Route::delete('settings/webhooks/{webhook}', [WebhookController::class, 'destroy'])
            ->name('webhooks.destroy');

        // Phase 8 — OIDC SSO settings (settings.manage).
        Route::get('settings/sso', [SsoSettingsController::class, 'edit'])
            ->name('sso.edit');
        Route::put('settings/sso', [SsoSettingsController::class, 'update'])
            ->name('sso.update');
        Route::post('settings/sso/test', [SsoSettingsController::class, 'test'])
            ->name('sso.test');

        // Post-Phase 17 — per-org outgoing email (SMTP) settings (settings.manage).
        Route::get('settings/email', [MailSettingsController::class, 'edit'])
            ->name('mail-settings.edit');
        Route::put('settings/email', [MailSettingsController::class, 'update'])
            ->name('mail-settings.update');
        Route::post('settings/email/test', [MailSettingsController::class, 'test'])
            ->name('mail-settings.test');

        // Phase 8 — advanced permissions: role editor (users.manage).
        Route::get('roles', [RolePermissionController::class, 'index'])
            ->name('roles.index');
        Route::get('roles/{role}/edit', [RolePermissionController::class, 'edit'])
            ->name('roles.edit');
        Route::put('roles/{role}', [RolePermissionController::class, 'update'])
            ->name('roles.update');

        Route::get('settings/schedule', [ScheduleController::class, 'edit'])
            ->middleware('permission:settings.manage')
            ->name('schedule.edit');
        Route::put('settings/schedule', [ScheduleController::class, 'update'])
            ->middleware('permission:settings.manage')
            ->name('schedule.update');
        // Phase 5 — My Overtime (§73-I). No permission key: the page only
        // lists the signed-in user's own records (like attendance.employee).
        Route::get('overtime', [OvertimeController::class, 'index'])
            ->name('overtime.index');
        // §73-E approval workflow — managers decide their team, HR/admin all.
        Route::get('overtime/queue', [OvertimeController::class, 'queue'])
            ->middleware('permission:overtime.manage')
            ->name('overtime.queue');
        Route::post('overtime/{overtimeRecord}/approve', [OvertimeController::class, 'approve'])
            ->middleware('permission:overtime.manage')
            ->name('overtime.approve');
        Route::post('overtime/{overtimeRecord}/reject', [OvertimeController::class, 'reject'])
            ->middleware('permission:overtime.manage')
            ->name('overtime.reject');
        Route::post('overtime/{overtimeRecord}/adjust', [OvertimeController::class, 'adjust'])
            ->middleware('permission:overtime.manage')
            ->name('overtime.adjust');

        // Phase 5 — effective-dated overtime policy (§73-G).
        Route::get('settings/overtime', [OvertimePolicyController::class, 'edit'])
            ->middleware('permission:settings.manage')
            ->name('overtime-policy.edit');
        Route::put('settings/overtime', [OvertimePolicyController::class, 'update'])
            ->middleware('permission:settings.manage')
            ->name('overtime-policy.update');

        // Phase 5 — scoring & risk settings (§15/§16): weights, status
        // bands, the weekly late-risk rule and the [OT] overwork notice.
        Route::get('settings/scoring', [ScoringSettingsController::class, 'edit'])
            ->middleware('permission:settings.manage')
            ->name('scoring-settings.edit');
        Route::put('settings/scoring', [ScoringSettingsController::class, 'update'])
            ->middleware('permission:settings.manage')
            ->name('scoring-settings.update');

        // Phase 4 — billing & subscription (§54): plans, usage cap, invoices.
        Route::get('billing', [BillingController::class, 'index'])
            ->middleware('permission:settings.manage')
            ->name('billing.index');
        Route::post('billing/plan', [BillingController::class, 'updatePlan'])
            ->middleware('permission:settings.manage')
            ->name('billing.plan');
        // Phase 9 — Stripe Checkout redirect (plans with a stripe_price_id).
        Route::post('billing/stripe/checkout', [BillingController::class, 'stripeCheckout'])
            ->middleware('permission:settings.manage')
            ->name('billing.stripe_checkout');
        // Phase 9 — Stripe Billing Portal (payment methods, invoices, cancel).
        Route::post('billing/stripe/portal', [BillingController::class, 'stripePortal'])
            ->middleware('permission:settings.manage')
            ->name('billing.stripe_portal');

        Route::get('attendance', [AttendanceController::class, 'index'])
            ->middleware('permission:attendance.view')
            ->name('attendance.index');
        // Phase 4 — live attendance board (§19): SSE stream + JSON poll
        // fallback for hosts where event streaming is unavailable.
        Route::get('attendance/live', [LiveAttendanceController::class, 'index'])
            ->middleware('permission:attendance.view')
            ->name('attendance.live.index');
        Route::get('attendance/live/stream', [LiveAttendanceController::class, 'stream'])
            ->middleware('permission:attendance.view')
            ->name('attendance.live.stream');
        Route::get('attendance/live/poll', [LiveAttendanceController::class, 'poll'])
            ->middleware('permission:attendance.view')
            ->name('attendance.live.poll');
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
        // Self-report fallback for a broken NFC terminal: no permission
        // middleware (the controller only ever acts on the user's own
        // employee record) + a light throttle; one report per direction
        // per day, immutable afterwards.
        Route::post('attendance/self-report', [AttendanceController::class, 'selfReport'])
            ->middleware('throttle:6,1')
            ->name('attendance.selfReport');

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
        // Phase 6 - overtime report (§41 [OT]).
        Route::get('reports/overtime', [ReportController::class, 'overtime'])
            ->middleware('permission:reports.view')
            ->name('reports.overtime');
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
        Route::post('leave/{leaveRequest}/notice', [LeaveController::class, 'addNotice'])
            ->middleware('permission:leave.approve')
            ->name('leave.notice');
        Route::post('leave/{leaveRequest}/cancel', [LeaveController::class, 'cancel'])
            ->name('leave.cancel');
        Route::get('leave/{leaveRequest}/edit', [LeaveController::class, 'edit'])
            ->middleware('permission:leave.approve')
            ->name('leave.edit');
        Route::put('leave/{leaveRequest}', [LeaveController::class, 'update'])
            ->middleware('permission:leave.approve')
            ->name('leave.update');
        Route::get('leave/{leaveRequest}/attachment', [LeaveController::class, 'attachment'])
            ->middleware('permission:leave.view')
            ->name('leave.attachment');
        Route::get('leave/{leaveRequest}', [LeaveController::class, 'show'])
            ->middleware('permission:leave.view')
            ->name('leave.show');

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
        // §43 — self-service data export (JSON download of own account data).
        Route::get('profile/export', [ProfileController::class, 'export'])
            ->name('profile.export');
        // Post-Phase 19 — profile customization: avatar upload + contact phone.
        Route::put('profile', [ProfileController::class, 'update'])
            ->name('profile.update');
    });

// §43 — privacy policy (public, no auth): discloses IP-derived location
// collection, retention windows and the data-export/deletion workflow.
Route::view('privacy', 'privacy')->name('privacy');

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
