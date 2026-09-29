<?php

namespace App\Http\Controllers;

use App\Models\Department;
use App\Models\Employee;
use App\Models\Position;
use App\Models\Role;
use App\Models\SalaryRecord;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\BillingService;
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

    public function create(): View
    {
        Gate::authorize('employees.create');

        return view('employees.create', [
            'departments' => Department::orderBy('name')->get(['id', 'name']),
            'positions' => Position::orderBy('title')->get(['id', 'title']),
            'managers' => User::whereHas('roles', fn ($q) => $q->whereIn('slug', ['company_admin', 'hr', 'manager']))
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
            'status' => ['required', Rule::in(['active', 'inactive'])],
            'employment_type' => ['required', Rule::in(['full_time', 'part_time', 'contract', 'intern'])],
            'phone' => ['nullable', 'string', 'max:32'],
            'joining_date' => ['nullable', 'date'],
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
                'joining_date' => $data['joining_date'] ?? now()->toDateString(),
            ]);
        });

        $this->audit->log('employee.created', $employee, null, [
            'employee_code' => $data['employee_code'],
            'email' => $data['email'],
            'name' => $data['name'],
        ]);

        return redirect()
            ->route('employees.show', $employee)
            ->with('status', 'Employee created.');
    }

    public function show(Employee $employee): View
    {
        Gate::authorize('employees.view');
        $this->authorizeOwnOrPermission($employee, 'employees.view_team');

        $employee->load(['user:id,name,email,last_login_at', 'department:id,name', 'position:id,title', 'manager:id,name']);

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

        return view('employees.show', compact('employee', 'canSeeSalary', 'currentSalary'));
    }

    public function edit(Employee $employee): View
    {
        Gate::authorize('employees.edit');
        $this->authorizeOwnOrPermission($employee, 'employees.view_team');

        return view('employees.edit', [
            'employee' => $employee->load(['user:id,name,email', 'department:id,name', 'position:id,title', 'manager:id,name']),
            'departments' => Department::orderBy('name')->get(['id', 'name']),
            'positions' => Position::orderBy('title')->get(['id', 'title']),
            'managers' => User::whereHas('roles', fn ($q) => $q->whereIn('slug', ['company_admin', 'hr', 'manager']))
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
        ]);

        $oldEmployee = $employee->only([
            'department_id', 'position_id', 'manager_id', 'status', 'employment_type',
            'phone', 'address', 'date_of_birth', 'joining_date', 'work_location',
            'photo_path',
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
        }

        $this->audit->log('employee.updated', $employee, [
            'employee' => $oldEmployee,
            'user' => $oldUser,
        ], [
            'employee' => $employee->fresh()->only(array_keys($oldEmployee)),
            'user' => $employee->user->fresh()->only(['name', 'email']),
        ]);

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
        $employee->delete();
        User::whereKey($userId)->delete();

        $this->audit->log('employee.deleted', null, $old, null);

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
