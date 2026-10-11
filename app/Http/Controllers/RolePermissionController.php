<?php

namespace App\Http\Controllers;

use App\Models\Role;
use App\Services\AuditLogger;
use App\Services\RoleSeederService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Phase 8 — advanced permissions: per-org role editor over the 30-key
 * catalog. The company_admin role is locked so an admin cannot lock the
 * tenant out of its own settings.
 */
class RolePermissionController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(Request $request): View
    {
        Gate::authorize('users.manage');

        $roles = Role::where('organization_id', $request->user()->organization_id)
            ->withCount('users')
            ->with('permissions:id,key')
            ->orderBy('id')
            ->get();

        return view('roles.index', compact('roles'));
    }

    public function edit(Request $request, Role $role): View
    {
        Gate::authorize('users.manage');
        abort_unless($role->organization_id === $request->user()->organization_id, 404);
        abort_if($role->slug === 'company_admin', 403, 'The Company Admin role is locked.');

        return view('roles.edit', [
            'role' => $role->load('permissions:id,key'),
            'catalog' => RoleSeederService::CATALOG,
        ]);
    }

    public function update(Request $request, Role $role): RedirectResponse
    {
        Gate::authorize('users.manage');
        abort_unless($role->organization_id === $request->user()->organization_id, 404);
        abort_if($role->slug === 'company_admin', 403, 'The Company Admin role is locked.');

        $allKeys = array_keys(RoleSeederService::CATALOG);

        $data = $request->validate([
            'permissions' => ['required', 'array', 'min:1'],
            'permissions.*' => [Rule::in($allKeys)],
        ]);

        $old = $role->permissions->pluck('key')->sort()->values()->all();
        $new = collect($data['permissions'])->sort()->values()->all();

        $ids = DB::table('permissions')->whereIn('key', $new)->pluck('id');
        $role->permissions()->sync($ids);

        $this->audit->log('role.permissions_updated', $role, ['permissions' => $old], ['permissions' => $new]);

        return redirect()
            ->route('roles.index')
            ->with('status', 'Permissions updated for '.$role->name.'.');
    }
}
