<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\StoreDepartmentRequest;
use App\Http\Requests\UpdateDepartmentRequest;
use App\Models\Department;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class DepartmentController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(Request $request): View
    {
        Gate::authorize('departments.view');

        $departments = Department::withCount('employees')
            ->with('manager:id,name')
            ->orderBy('name')
            ->when($request->filled('q'), function ($q) use ($request) {
                $term = $request->string('q')->toString();
                $q->where(fn ($w) => $w->where('name', 'like', "%{$term}%")
                    ->orWhere('code', 'like', "%{$term}%"));
            })
            ->paginate(15)
            ->withQueryString();

        return view('departments.index', compact('departments'));
    }

    public function create(): View
    {
        Gate::authorize('departments.manage');

        return view('departments.create', ['managers' => $this->managers()]);
    }

    public function store(StoreDepartmentRequest $request): RedirectResponse
    {
        $data = $request->validated();

        $department = Department::create($data);

        $this->audit->log('department.created', $department, null, $department->toArray());

        return redirect()
            ->route('departments.index')
            ->with('status', 'Department created.');
    }

    public function edit(Department $department): View
    {
        Gate::authorize('departments.manage');

        return view('departments.edit', [
            'department' => $department,
            'managers' => $this->managers(),
        ]);
    }

    public function update(UpdateDepartmentRequest $request, Department $department): RedirectResponse
    {
        $data = $request->validated();

        $old = $department->only(array_keys($data));
        $department->update($data);

        $this->audit->log('department.updated', $department, $old, $department->toArray());

        return redirect()
            ->route('departments.index')
            ->with('status', 'Department updated.');
    }

    public function destroy(Request $request, Department $department): RedirectResponse
    {
        Gate::authorize('departments.manage');

        $old = $department->toArray();
        $department->delete();

        $this->audit->log('department.deleted', null, $old, null);

        return redirect()
            ->route('departments.index')
            ->with('status', 'Department deleted.');
    }

    /**
     * Manager candidates for the department form — leadership roles only,
     * same list the employee form offers, scoped to the actor's org so the
     * dropdown never lists another tenant's users.
     *
     * @return Collection<int, User>
     */
    private function managers()
    {
        return User::where('organization_id', auth()->user()->organization_id)
            ->whereHas('roles', fn ($q) => $q->whereIn('slug', ['company_admin', 'hr', 'manager']))
            ->orderBy('name')
            ->get(['id', 'name']);
    }
}
