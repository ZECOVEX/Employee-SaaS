<?php

namespace App\Http\Controllers;

use App\Models\Department;
use App\Models\Position;
use App\Services\AuditLogger;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Positions (designations) — the per-tenant catalog employees pick from on
 * the employee form. Gated by `positions.manage` (company_admin per the
 * RBAC catalog); the list page is management-only, while every employee
 * form reads the org-scoped Position table directly for its dropdown.
 */
class PositionController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(Request $request): View
    {
        Gate::authorize('positions.manage');

        $positions = Position::with('department:id,name')
            ->withCount('employees')
            ->orderBy('title')
            ->when($request->filled('q'), function ($q) use ($request) {
                $term = $request->string('q')->toString();
                $q->where('title', 'like', "%{$term}%");
            })
            ->paginate(15)
            ->withQueryString();

        return view('positions.index', compact('positions'));
    }

    public function create(): View
    {
        Gate::authorize('positions.manage');

        return view('positions.create', ['departments' => $this->departments()]);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('positions.manage');

        $data = $request->validate([
            'title' => ['required', 'string', 'max:120', Rule::unique('positions', 'title')
                ->where('organization_id', $request->user()->organization_id)],
            'department_id' => ['nullable', Rule::exists('departments', 'id')
                ->where('organization_id', $request->user()->organization_id)],
        ]);

        $position = Position::create($data);

        $this->audit->log('position.created', $position, null, $position->toArray());

        return redirect()
            ->route('positions.index')
            ->with('status', 'Position created.');
    }

    public function edit(Position $position): View
    {
        Gate::authorize('positions.manage');

        return view('positions.edit', [
            'position' => $position,
            'departments' => $this->departments(),
        ]);
    }

    public function update(Request $request, Position $position): RedirectResponse
    {
        Gate::authorize('positions.manage');

        $data = $request->validate([
            'title' => ['required', 'string', 'max:120', Rule::unique('positions', 'title')
                ->where('organization_id', $request->user()->organization_id)
                ->ignore($position->id)],
            'department_id' => ['nullable', Rule::exists('departments', 'id')
                ->where('organization_id', $request->user()->organization_id)],
        ]);

        $old = $position->only(array_keys($data));
        $position->update($data);

        $this->audit->log('position.updated', $position, $old, $position->toArray());

        return redirect()
            ->route('positions.index')
            ->with('status', 'Position updated.');
    }

    public function destroy(Position $position): RedirectResponse
    {
        Gate::authorize('positions.manage');

        $old = $position->toArray();
        $position->delete();

        $this->audit->log('position.deleted', null, $old, null);

        return redirect()
            ->route('positions.index')
            ->with('status', 'Position deleted.');
    }

    /**
     * Department candidates for the position form — org-scoped through the
     * Position/Department global tenant scope.
     *
     * @return Collection<int, Department>
     */
    private function departments()
    {
        return Department::orderBy('name')->get(['id', 'name']);
    }
}
