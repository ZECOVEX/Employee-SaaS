<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Organization;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class PlatformController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function organizations(): View
    {
        Gate::authorize('platform.organizations');

        $organizations = Organization::withCount('users', 'employees')
            ->orderByDesc('id')
            ->paginate(20);

        return view('platform.organizations', compact('organizations'));
    }

    public function toggleStatus(Request $request, Organization $organization): RedirectResponse
    {
        Gate::authorize('platform.organizations');

        $old = ['status' => $organization->status];

        $organization->update([
            'status' => $organization->status === 'active' ? 'suspended' : 'active',
        ]);

        $this->audit->log('organization.status_changed', $organization, $old, [
            'status' => $organization->status,
        ]);

        return back()->with('status', 'Organization status updated.');
    }
}
