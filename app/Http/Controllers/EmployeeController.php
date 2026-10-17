<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeMonthlyStatus;
use App\Models\LeaveRequest;
use App\Models\NfcCard;
use App\Models\Position;
use App\Models\Role;
use App\Models\SalaryRecord;
use App\Models\User;
use App\Models\UserLoginHistory;
use App\Notifications\EmploymentStatusChanged;
use App\Notifications\PasswordChangeRequired;
use App\Services\AuditLogger;
use App\Services\BillingService;
use App\Services\OvertimeService;
use App\Services\RiskService;
use App\Services\ScoreService;
use App\Services\Webhooks\WebhookEvent;
use App\Services\Webhooks\WebhookService;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

class EmployeeController extends Controller
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly BillingService $billing,
    ) {}

    public function index(Request $request): View
    {
        Gate::authorize('employees.view');

        $user = $request->user();

        $employees = Employee::with(['user:id,name,email', 'department:id,name', 'position:id,title'])
            ->when(! $user->is_platform_admin && $user->hasRole('manager') && ! $user->hasAnyRole(['company_admin', 'hr']),
                fn ($q) => $q->where('manager_id', $user->id))
            ->when($request->filled('q'), function ($q) use ($request) {
                $term = $request->string('q')->toString();
                $q->where(function ($w) use ($term) {
                    $w->where('employee_code', 'like', "%{$term}%")
                        ->orWhereHas('user', fn ($u) => $u->where('name', 'like', "%{$term}%")
                            ->orWhere('email', 'like', "%{$term}%"));
                });
            })
            ->when($request->filled('department_id'), fn ($q) => $q->where('department_id', $request->integer('department_id')))
            ->orderBy('employee_code')
            ->paginate(15)
            ->withQueryString();

        $departments = Department::orderBy('name')->get(['id', 'name']);

        return view('employees.index', compact('employees', 'departments'));
    }

    public function create(Request $request): View
    {
        Gate::authorize('employees.create');

        return view('employees.create', [
            'departments' => Department::orderBy('name')->get(['id', 'name']),
            'positions' => Position::orderBy('title')->get(['id', 'title']),
            'managers' => User::where('organization_id', $request->user()->organization_id)
                ->whereHas('roles', fn ($q) => $q->whereIn('slug', ['company_admin', 'hr', 'manager']))
                ->orderBy('name')->get(['id', 'name']),
            'roles' => Role::orderBy('name')->get(['id', 'name', 'slug']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('employees.create');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:190', Rule::unique('users', 'email')],
            'password' => ['required', 'confirmed', Password::defaults()],
            'employee_code' => ['required', 'string', 'max:40', Rule::unique('employees', 'employee_code')
                ->where('organization_id', $request->user()->organization_id)],
            'department_id' => ['nullable', Rule::exists('departments', 'id')
                ->where('organization_id', $request->user()->organization_id)],
            'position_id' => ['nullable', Rule::exists('positions', 'id')
                ->where('organization_id', $request->user()->organization_id)],
            'manager_id' => ['nullable', Rule::exists('users', 'id')
                ->where('organization_id', $request->user()->organization_id)],
            'status' => ['required', Rule::in(['active', 'inactive', 'terminated'])],
            'employment_type' => ['required', Rule::in(['full_time', 'part_time', 'contract', 'intern'])],
            'phone' => ['nullable', 'string', 'max:32'],
            'address' => ['nullable', 'string', 'max:255'],
            'date_of_birth' => ['nullable', 'date'],
            'joining_date' => ['nullable', 'date'],
            'work_location' => ['nullable', 'string', 'max:120'],
            'emergency_contact_name' => ['nullable', 'string', 'max:120'],
            'emergency_contact_phone' => ['nullable', 'string', 'max:32'],
            'photo' => ['nullable', 'image', 'max:2048'],
            'overtime_eligibility' => ['nullable', Rule::in([
                Employee::OT_POLICY_DEFAULT,
                Employee::OT_ELIGIBLE,
                Employee::OT_NOT_ELIGIBLE,
            ])],
            'role_id' => ['nullable', Rule::exists('roles', 'id')
                ->where('organization_id', $request->user()->organization_id)],
        ]);

        // Plan headcount cap (§54) — no subscription/plan means no limit.
        $this->billing->ensureCanAddEmployee($request->user()->organization_id);

        $user = null;
        $employee = null;

        DB::transaction(function () use ($data, $request, &$user, &$employee) {
            $user = User::create([
                'organization_id' => $request->user()->organization_id,
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => Hash::make($data['password']),
                'email_verified_at' => now(),
                // Admin-set initial password: must be changed at first login.
                'must_change_password' => true,
            ]);

            $roleId = $data['role_id']
                ?? Role::where('organization_id', $request->user()->organization_id)
                    ->where('slug', 'employee')->value('id');

            if ($roleId) {
                $user->roles()->attach($roleId);
            }

            $employee = Employee::create([
                'organization_id' => $request->user()->organization_id,
                'user_id' => $user->id,
                'employee_code' => $data['employee_code'],
                'department_id' => $data['department_id'] ?? null,
                'position_id' => $data['position_id'] ?? null,
                'manager_id' => $data['manager_id'] ?? null,
                'status' => $data['status'],
                'employment_type' => $data['employment_type'],
                'phone' => $data['phone'] ?? null,
                'address' => $data['address'] ?? null,
                'date_of_birth' => $data['date_of_birth'] ?? null,
                'joining_date' => $data['joining_date'] ?? now()->toDateString(),
                'work_location' => $data['work_location'] ?? null,
                'emergency_contact_name' => $data['emergency_contact_name'] ?? null,
                'emergency_contact_phone' => $data['emergency_contact_phone'] ?? null,
                'overtime_eligibility' => $data['overtime_eligibility'] ?? Employee::OT_POLICY_DEFAULT,
            ]);
        });

        // Same post-commit photo handling as update() — a rolled-back create
        // never leaves an orphaned file.
        if ($request->hasFile('photo')) {
            $newPhotoPath = $request->file('photo')->store('employees/'.$employee->id, 'public');
            $employee->update(['photo_path' => $newPhotoPath]);

            // Post-Phase 20b — mirror the admin-set photo onto the user row so
            // <x-avatar> (nav, profile) renders it everywhere for the employee.
            $employee->user->update(['avatar_path' => $newPhotoPath]);
        }

        $this->audit->log('employee.created', $employee, null, [
            'employee_code' => $data['employee_code'],
            'email' => $data['email'],
            'name' => $data['name'],
            'status' => $data['status'],
            'employment_type' => $data['employment_type'],
            'department_id' => $data['department_id'] ?? null,
            'overtime_eligibility' => $data['overtime_eligibility'] ?? null,
        ]);

        // New accounts must change the admin-set password at first login;
        // the bell notice + app banner stay until they do.
        $user->notify(new PasswordChangeRequired);

        app(WebhookService::class)->dispatch(
            WebhookEvent::EMPLOYEE_CREATED,
            (int) $request->user()->organization_id,
            ['employee_id' => $employee->id, 'employee_code' => $employee->employee_code],
        );

        return redirect()
            ->route('employees.show', $employee)
            ->with('status', 'Employee created.');
    }

    public function show(Request $request, Employee $employee, ScoreService $scoring, OvertimeService $overtime, RiskService $risk): View
    {
        Gate::authorize('employees.view');
        $this->authorizeOwnOrPermission($employee, 'employees.view_team');

        $employee->load(['user:id,name,email,last_login_at', 'department:id,name', 'position:id,title', 'manager:id,name']);

        $user = $request->user();
        $isSelf = auth()->user()?->employee?->id === $employee->id;
        $canSeeSalary = Gate::allows('salary.view')
            || ($isSelf && Gate::allows('salary.view_own'));

        $currentSalary = $canSeeSalary
            ? SalaryRecord::effectiveFor(
                (int) $employee->organization_id,
                $employee->id,
                now($employee->organization?->timezone ?? 'UTC')->toDateString(),
            )
            : null;

        // §40 header cards: current-month attendance score + overtime.
        $tz = $employee->organization?->timezone ?? 'UTC';
        $month = now($tz)->format('Y-m');
        $score = $scoring->compute($employee, $month);
        $scoreStatus = $score !== null
            ? $risk->statusFor($employee->organization, (float) $score['score'])
            : null;
        $otSummary = $overtime->summaryForRange(
            $employee,
            now($tz)->startOfMonth()->toDateString(),
            now($tz)->endOfMonth()->toDateString(),
        );

        // §40 tabs — each visible only to self or the matching permission.
        $tabs = array_keys(array_filter([
            'leave' => $isSelf || $user->canPermission('leave.view'),
            'monthly' => true,
            'nfc' => $isSelf || $user->canPermission('nfc.view'),
            'audit' => $isSelf || $user->canPermission('audit.view'),
        ]));

        $requested = $request->string('tab')->toString();
        $tab = in_array($requested, $tabs, true) ? $requested : ($tabs[0] ?? 'monthly');

        $joiningMonthKey = $employee->joining_date !== null
            ? (int) Carbon::parse($employee->joining_date)->format('Ym')
            : null;

        $tabData = match ($tab) {
            'leave' => LeaveRequest::with('leaveType:id,name,code')
                ->where('employee_id', $employee->id)
                ->orderByDesc('created_at')
                ->limit(30)
                ->get(),
            'nfc' => NfcCard::where('employee_id', $employee->id)
                ->orderByDesc('id')
                ->limit(30)
                ->get(),
            'audit' => AuditLog::with('actor:id,name')
                ->where('resource_type', 'Employee')
                ->where('resource_id', $employee->id)
                ->orderByDesc('id')
                ->limit(30)
                ->get(),
            default => EmployeeMonthlyStatus::where('employee_id', $employee->id)
                ->when(
                    $joiningMonthKey !== null,
                    fn ($query) => $query->whereRaw('year * 100 + month >= ?', [$joiningMonthKey]),
                )
                ->orderByDesc('year')
                ->orderByDesc('month')
                ->limit(24)
                ->get(),
        };

        // §71 — login history on the Audit History tab (self or audit.view).
        $loginHistory = ($tab === 'audit' && ($isSelf || $user->canPermission('audit.view')) && $employee->user_id !== null)
            ? UserLoginHistory::where('user_id', $employee->user_id)
                ->orderByDesc('id')
                ->limit(10)
                ->get()
            : collect();

        return view('employees.show', compact(
            'employee', 'canSeeSalary', 'currentSalary',
            'score', 'scoreStatus', 'otSummary',
            'tabs', 'tab', 'tabData', 'isSelf', 'loginHistory',
        ));
    }

    public function edit(Employee $employee): View
    {
        Gate::authorize('employees.edit');
        $this->authorizeOwnOrPermission($employee, 'employees.view_team');

        return view('employees.edit', [
            'employee' => $employee->load(['user:id,name,email', 'department:id,name', 'position:id,title', 'manager:id,name']),
            'departments' => Department::orderBy('name')->get(['id', 'name']),
            'positions' => Position::orderBy('title')->get(['id', 'title']),
            'managers' => User::where('organization_id', $employee->organization_id)
                ->whereHas('roles', fn ($q) => $q->whereIn('slug', ['company_admin', 'hr', 'manager']))
                ->where('id', '!=', $employee->user_id)
                ->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function update(Request $request, Employee $employee): RedirectResponse
    {
        Gate::authorize('employees.edit');
        $this->authorizeOwnOrPermission($employee, 'employees.view_team');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:190', Rule::unique('users', 'email')->ignore($employee->user_id)],
            'department_id' => ['nullable', Rule::exists('departments', 'id')
                ->where('organization_id', $request->user()->organization_id)],
            'position_id' => ['nullable', Rule::exists('positions', 'id')
                ->where('organization_id', $request->user()->organization_id)],
            'manager_id' => ['nullable', Rule::exists('users', 'id')
                ->where('organization_id', $request->user()->organization_id)],
            'status' => ['required', Rule::in(['active', 'inactive', 'terminated'])],
            'employment_type' => ['required', Rule::in(['full_time', 'part_time', 'contract', 'intern'])],
            'phone' => ['nullable', 'string', 'max:32'],
            'address' => ['nullable', 'string', 'max:255'],
            'date_of_birth' => ['nullable', 'date'],
            'joining_date' => ['nullable', 'date'],
            'work_location' => ['nullable', 'string', 'max:120'],
            'emergency_contact_name' => ['nullable', 'string', 'max:120'],
            'emergency_contact_phone' => ['nullable', 'string', 'max:32'],
            'photo' => ['nullable', 'image', 'max:2048'],
            'overtime_eligibility' => ['nullable', Rule::in([
                Employee::OT_POLICY_DEFAULT,
                Employee::OT_ELIGIBLE,
                Employee::OT_NOT_ELIGIBLE,
            ])],
        ]);

        // §54: reactivating an inactive/terminated employee consumes plan
        // headcount just like a create — same cap, no bypass on update.
        if ($data['status'] === 'active' && $employee->status !== 'active') {
            $this->billing->ensureCanAddEmployee($employee->organization_id);
        }

        $oldEmployee = $employee->only([
            'department_id', 'position_id', 'manager_id', 'status', 'employment_type',
            'phone', 'address', 'date_of_birth', 'joining_date', 'work_location',
            'photo_path', 'overtime_eligibility',
        ]);
        $oldUser = $employee->user->only(['name', 'email']);
        $oldPhotoPath = $employee->photo_path;

        DB::transaction(function () use ($data, $employee) {
            $employee->update([
                'department_id' => $data['department_id'] ?? null,
                'position_id' => $data['position_id'] ?? null,
                'manager_id' => $data['manager_id'] ?? null,
                'status' => $data['status'],
                'employment_type' => $data['employment_type'],
                'phone' => $data['phone'] ?? null,
                'address' => $data['address'] ?? null,
                'date_of_birth' => $data['date_of_birth'] ?? null,
                'joining_date' => $data['joining_date'] ?? null,
                'work_location' => $data['work_location'] ?? null,
                'emergency_contact_name' => $data['emergency_contact_name'] ?? null,
                'emergency_contact_phone' => $data['emergency_contact_phone'] ?? null,
                'overtime_eligibility' => $data['overtime_eligibility'] ?? $employee->overtime_eligibility,
            ]);

            $employee->user->update([
                'name' => $data['name'],
                'email' => $data['email'],
            ]);
        });

        // Store the photo only after the transaction commits, so a rolled-back
        // update never leaves an orphaned file on disk; the old file is removed
        // only once the new path has been persisted.
        if ($request->hasFile('photo')) {
            $newPhotoPath = $request->file('photo')->store('employees/'.$employee->id, 'public');
            $employee->update(['photo_path' => $newPhotoPath]);

            if ($oldPhotoPath) {
                Storage::disk('public')->delete($oldPhotoPath);
            }

            // Post-Phase 20b — mirror onto the user row so <x-avatar> (nav,
            // profile) shows the admin-set photo everywhere; a self-uploaded
            // avatars/ file is superseded and removed.
            $previousUserAvatar = $employee->user->avatar_path;

            $employee->user->update(['avatar_path' => $newPhotoPath]);

            if ($previousUserAvatar !== null
                && $previousUserAvatar !== $oldPhotoPath
                && $previousUserAvatar !== $newPhotoPath) {
                Storage::disk('public')->delete($previousUserAvatar);
            }
        }

        $this->audit->log('employee.updated', $employee, [
            'employee' => $oldEmployee,
            'user' => $oldUser,
        ], [
            'employee' => $employee->fresh()->only(array_keys($oldEmployee)),
            'user' => $employee->user->fresh()->only(['name', 'email']),
        ]);

        app(WebhookService::class)->dispatch(
            WebhookEvent::EMPLOYEE_UPDATED,
            (int) $request->user()->organization_id,
            ['employee_id' => $employee->id, 'employee_code' => $employee->employee_code],
        );

        // Tell the employee when their employment status becomes inactive or
        // terminated (they can still sign in; a badge + dashboard block mirror
        // this notice — the PasswordChangeRequired pattern).
        if ($oldEmployee['status'] !== $data['status']
            && in_array($data['status'], ['inactive', 'terminated'], true)) {
            $employee->user?->notify(new EmploymentStatusChanged($data['status']));
        }

        return redirect()
            ->route('employees.show', $employee)
            ->with('status', 'Employee updated.');
    }

    public function destroy(Request $request, Employee $employee): RedirectResponse
    {
        Gate::authorize('employees.delete');

        $old = [
            'employee' => $employee->toArray(),
            'email' => $employee->user?->email,
        ];

        $userId = $employee->user_id;
        $organizationId = (int) $employee->organization_id;
        $employee->delete();
        User::whereKey($userId)->delete();

        $this->audit->log('employee.deleted', null, $old, null);

        app(WebhookService::class)->dispatch(
            WebhookEvent::EMPLOYEE_DELETED,
            $organizationId,
            ['employee_id' => $employee->id, 'employee_code' => $employee->employee_code],
        );

        return redirect()
            ->route('employees.index')
            ->with('status', 'Employee deleted.');
    }

    private function authorizeOwnOrPermission(Employee $employee, string $permission): void
    {
        $user = auth()->user();

        if ($user->id === $employee->user_id) {
            return;
        }

        if ($user->canPermission($permission) || $user->canPermission('employees.view')) {
            // Managers with view_team only see their team (enforced in index; show checks manager)
            if ($user->hasAnyRole(['company_admin', 'hr']) || $user->is_platform_admin) {
                return;
            }

            if ($user->hasRole('manager') && $employee->manager_id === $user->id) {
                return;
            }

            if ($user->canPermission('employees.view') && ! $user->hasRole('manager')) {
                return;
            }
        }

        abort(403);
    }
}
