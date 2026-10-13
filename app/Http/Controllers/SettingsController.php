<?php

declare(strict_types=1);

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

            // §43 — data retention windows (days); 0 = use the platform default.
            'audit_retention_days' => ['sometimes', 'integer', 'min:0', 'max:3650'],
            'login_retention_days' => ['sometimes', 'integer', 'min:0', 'max:3650'],

            // Self-report attendance fallback (terminal-down alternative).
            'self_attendance_enabled' => ['sometimes', Rule::in(['0', '1'])],
        ]);

        $salaryKeys = [
            'currency', 'late_deduction', 'deduction_grace_minutes', 'deduction_rounding',
            'absence_deduction', 'half_day_deduction', 'unpaid_leave_deduction', 'max_deduction_percent',
        ];

        $retentionKeys = ['audit_retention_days', 'login_retention_days'];

        $attendanceKeys = ['self_attendance_enabled'];

        $old = [
            'name' => $organization->name,
            'timezone' => $organization->timezone,
            'debounce_seconds' => $organization->debounceSeconds(),
            'time_format' => $organization->timeFormat(),
        ];

        // Salary rules live in settings — capture their previous values too
        // so the audit diff shows real old -> new pairs (§28/Phase 7).
        foreach ($salaryKeys as $key) {
            if (array_key_exists($key, $data)) {
                $old[$key] = ($organization->settings ?? [])[$key] ?? null;
            }
        }

        foreach ($retentionKeys as $key) {
            if (array_key_exists($key, $data)) {
                $old[$key] = ($organization->settings ?? [])[$key] ?? null;
            }
        }

        foreach ($attendanceKeys as $key) {
            if (array_key_exists($key, $data)) {
                $old[$key] = ($organization->settings ?? [])[$key] ?? null;
            }
        }

        $settings = array_merge($organization->settings ?? [], [
            'debounce_seconds' => (int) $data['debounce_seconds'],
            'time_format' => $data['time_format'],
        ]);

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

        $retentionChanges = [];

        foreach ($retentionKeys as $key) {
            if (! array_key_exists($key, $data)) {
                continue;
            }

            // 0 means "use the platform default" — store null so the prune
            // command falls back to config('privacy.*').
            $value = (int) $data[$key] > 0 ? (int) $data[$key] : null;
            $settings[$key] = $value;
            $retentionChanges[$key] = $value;
        }

        $attendanceChanges = [];

        foreach ($attendanceKeys as $key) {
            if (! array_key_exists($key, $data)) {
                continue;
            }

            $value = $data[$key] === '1';
            $settings[$key] = $value;
            $attendanceChanges[$key] = $value;
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
            ...$retentionChanges,
            ...$attendanceChanges,
        ]);

        return redirect()
            ->route('settings.edit')
            ->with('status', 'Settings saved.');
    }
}
