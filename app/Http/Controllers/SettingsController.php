<?php

namespace App\Http\Controllers;

use App\Models\Organization;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
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
            'debounce_seconds' => ['required', 'integer', 'min:5', 'max:300'],
            'time_format' => ['required', Rule::in(['24h', '12h'])],

            // Salary & deduction rules (§28) — optional so older clients/tests
            // without these fields keep working; the settings form always sends them.
            'currency' => ['sometimes', 'string', 'max:8', 'regex:/^[A-Za-z]{3,8}$/'],
            'late_deduction' => ['sometimes', Rule::in(['per_minute', 'none'])],
            'deduction_grace_minutes' => ['sometimes', 'integer', 'min:0', 'max:240'],
            'deduction_rounding' => ['sometimes', Rule::in(['exact', 'nearest', 'whole'])],
            'absence_deduction' => ['sometimes', Rule::in(['full_day', 'half_day', 'none'])],
            'half_day_deduction' => ['sometimes', Rule::in(['full_day', 'half_day', 'none'])],
            'unpaid_leave_deduction' => ['sometimes', Rule::in(['0', '1'])],
            'max_deduction_percent' => ['sometimes', 'integer', 'min:0', 'max:100'],
        ]);

        $old = [
            'name' => $organization->name,
            'timezone' => $organization->timezone,
            'debounce_seconds' => $organization->debounceSeconds(),
            'time_format' => $organization->timeFormat(),
        ];

        $settings = array_merge($organization->settings ?? [], [
            'debounce_seconds' => (int) $data['debounce_seconds'],
            'time_format' => $data['time_format'],
        ]);

        $salaryKeys = [
            'currency', 'late_deduction', 'deduction_grace_minutes', 'deduction_rounding',
            'absence_deduction', 'half_day_deduction', 'unpaid_leave_deduction', 'max_deduction_percent',
        ];
        $salaryChanges = [];

        foreach ($salaryKeys as $key) {
            if (! array_key_exists($key, $data)) {
                continue;
            }

            $value = in_array($key, ['deduction_grace_minutes', 'max_deduction_percent'], true)
                ? (int) $data[$key]
                : ($key === 'unpaid_leave_deduction' ? $data[$key] === '1' : $data[$key]);

            $settings[$key] = $value;
            $salaryChanges[$key] = $value;
        }

        $organization->update([
            'name' => $data['name'],
            'timezone' => $data['timezone'],
            'settings' => $settings,
        ]);

        $this->audit->log('organization.updated', $organization, $old, [
            'name' => $data['name'],
            'timezone' => $data['timezone'],
            'debounce_seconds' => (int) $data['debounce_seconds'],
            'time_format' => $data['time_format'],
            ...$salaryChanges,
        ]);

        return redirect()
            ->route('settings.edit')
            ->with('status', 'Settings saved.');
    }
}
