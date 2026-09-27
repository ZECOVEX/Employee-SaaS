<?php

namespace App\Http\Controllers;

use App\Models\Organization;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class SettingsController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function edit(Request $request): View
    {
        Gate::authorize('settings.manage');

        $organization = $request->user()->organization;

        return view('settings.edit', compact('organization'));
    }

    public function update(Request $request): RedirectResponse
    {
        Gate::authorize('settings.manage');

        /** @var Organization $organization */
        $organization = $request->user()->organization;

        $data = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'timezone' => ['required', 'string', 'max:64'],
        ]);

        $old = $organization->only(array_keys($data));
        $organization->update($data);

        $this->audit->log('organization.updated', $organization, $old, $data);

        return redirect()
            ->route('settings.edit')
            ->with('status', 'Settings saved.');
    }
}
