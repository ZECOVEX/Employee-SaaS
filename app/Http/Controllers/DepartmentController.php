<?php

namespace App\Http\Controllers;

use App\Models\Department;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
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

        return view('departments.create');
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('departments.manage');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120', Rule::unique('departments', 'name')
                ->where('organization_id', $request->user()->organization_id)],
            'code' => ['nullable', 'string', 'max:40'],
            'description' => ['nullable', 'string', 'max:1000'],
        ]);

        $department = Department::create($data);

        $this->audit->log('department.created', $department, null, $department->toArray());

        return redirect()
            ->route('departments.index')
            ->with('status', 'Department created.');
    }

    public function edit(Department $department): View
    {
        Gate::authorize('departments.manage');

        return view('departments.edit', compact('department'));
    }

    public function update(Request $request, Department $department): RedirectResponse
    {
        Gate::authorize('departments.manage');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120', Rule::unique('departments', 'name')
                ->where('organization_id', $request->user()->organization_id)
                ->ignore($department->id)],
            'code' => ['nullable', 'string', 'max:40'],
            'description' => ['nullable', 'string', 'max:1000'],
        ]);

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
}
