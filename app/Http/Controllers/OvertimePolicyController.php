<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\OvertimePolicy;
use App\Services\AuditLogger;
use App\Services\OvertimeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Company overtime policy (§73-G) — effective-dated versions like work
 * schedules, so a change never rewrites history.
 */
class OvertimePolicyController extends Controller
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly OvertimeService $overtime,
    ) {}

    public function edit(Request $request): View
    {
        Gate::authorize('settings.manage');

        $policy = $this->currentPolicy((int) $request->user()->organization_id);

        return view('overtime.policy', compact('policy'));
    }

    public function update(Request $request): RedirectResponse
    {
        Gate::authorize('settings.manage');

        $organization = $request->user()->organization;
        $policy = $this->currentPolicy((int) $organization->id);

        $bool = ['nullable', Rule::in(['0', '1'])];

        $data = $request->validate([
            'enabled' => $bool,
            'mode' => ['required', Rule::in([OvertimePolicy::MODE_AFTER_END, OvertimePolicy::MODE_ABOVE_EXPECTED])],
            'start_threshold_minutes' => ['required', 'integer', 'min:0', 'max:720'],
            'rounding_minutes' => ['required', 'integer', 'min:1', 'max:720'],
            'rounding_method' => ['required', Rule::in([OvertimePolicy::ROUND_UP, OvertimePolicy::ROUND_DOWN, OvertimePolicy::ROUND_NEAREST])],
            'daily_cap_minutes' => ['required', 'integer', 'min:0', 'max:1440'],
            'weekly_cap_minutes' => ['required', 'integer', 'min:0', 'max:10080'],
            'monthly_cap_minutes' => ['nullable', 'integer', 'min:0', 'max:43200'],
            'max_shift_minutes' => ['required', 'integer', 'min:60', 'max:2880'],
            'approval_mode' => ['required', Rule::in([OvertimePolicy::APPROVE_AUTO, OvertimePolicy::APPROVE_MANAGER, OvertimePolicy::APPROVE_MANAGER_HR])],
            'pending_expiry_days' => ['required', 'integer', 'min:1', 'max:60'],
            'pending_expiry_action' => ['required', Rule::in([OvertimePolicy::EXPIRE_AUTO_APPROVE, OvertimePolicy::EXPIRE_AUTO_REJECT, OvertimePolicy::EXPIRE_ESCALATE])],
            'weekday_multiplier' => ['required', 'numeric', 'min:1', 'max:5'],
            'weekend_multiplier' => ['required', 'numeric', 'min:1', 'max:5'],
            'holiday_multiplier' => ['required', 'numeric', 'min:1', 'max:5'],
            'night_window_start' => ['nullable', 'date_format:H:i'],
            'night_window_end' => ['nullable', 'date_format:H:i', 'required_if:night_window_start,*'],
            'night_multiplier' => ['nullable', 'numeric', 'min:1', 'max:5', 'required_if:night_window_start,*'],
            'rate_base' => ['required', Rule::in([OvertimePolicy::RATE_GROSS, OvertimePolicy::RATE_BASIC, OvertimePolicy::RATE_CUSTOM])],
            'offset_late_with_overtime' => $bool,
            'weekend_all_overtime' => $bool,
            'require_pre_approval' => $bool,
            'show_pay_to_employee' => $bool,
            'show_hours_to_employee' => $bool,
            'compensation_type' => ['required', Rule::in([OvertimePolicy::COMP_PAID, OvertimePolicy::COMP_TIME])],
            'overtime_score_bonus_max' => ['required', 'integer', 'min:0', 'max:10'],
        ], [
            'night_window_end.required_if' => 'Night end is required when night start is set.',
            'night_multiplier.required_if' => 'Night multiplier is required when the night window is set.',
        ]);

        $attributes = [
            'enabled' => ($data['enabled'] ?? '1') === '1',
            'mode' => $data['mode'],
            'start_threshold_minutes' => (int) $data['start_threshold_minutes'],
            'rounding_minutes' => (int) $data['rounding_minutes'],
            'rounding_method' => $data['rounding_method'],
            'daily_cap_minutes' => (int) $data['daily_cap_minutes'],
            'weekly_cap_minutes' => (int) $data['weekly_cap_minutes'],
            'monthly_cap_minutes' => isset($data['monthly_cap_minutes']) && $data['monthly_cap_minutes'] !== ''
                ? (int) $data['monthly_cap_minutes'] : null,
            'max_shift_minutes' => (int) $data['max_shift_minutes'],
            'approval_mode' => $data['approval_mode'],
            'pending_expiry_days' => (int) $data['pending_expiry_days'],
            'pending_expiry_action' => $data['pending_expiry_action'],
            'weekday_multiplier' => (float) $data['weekday_multiplier'],
            'weekend_multiplier' => (float) $data['weekend_multiplier'],
            'holiday_multiplier' => (float) $data['holiday_multiplier'],
            'night_window_start' => $data['night_window_start'] ?? null,
            'night_window_end' => $data['night_window_end'] ?? null,
            'night_multiplier' => isset($data['night_multiplier']) && $data['night_multiplier'] !== ''
                ? (float) $data['night_multiplier'] : null,
            'rate_base' => $data['rate_base'],
            'offset_late_with_overtime' => ($data['offset_late_with_overtime'] ?? '0') === '1',
            'weekend_all_overtime' => ($data['weekend_all_overtime'] ?? '0') === '1',
            'require_pre_approval' => ($data['require_pre_approval'] ?? '0') === '1',
            'show_pay_to_employee' => ($data['show_pay_to_employee'] ?? '0') === '1',
            'show_hours_to_employee' => ($data['show_hours_to_employee'] ?? '0') === '1',
            'compensation_type' => $data['compensation_type'],
            'overtime_score_bonus_max' => (int) $data['overtime_score_bonus_max'],
        ];

        $old = $policy->exists ? $policy->only(array_keys($attributes)) : [];
        $timezone = $organization->timezone ?? 'UTC';
        $today = now($timezone)->toDateString();

        $saved = DB::transaction(function () use ($policy, $attributes, $request, $today, $timezone) {
            if ($policy->exists && $policy->effective_from?->toDateString() !== $today) {
                // Effective dating (§11/§73-G): close the current version so
                // past dates keep the policy they were calculated with.
                $policy->update([
                    'effective_to' => now($timezone)->subDay()->toDateString(),
                ]);

                return OvertimePolicy::withoutGlobalScopes()->create([
                    'organization_id' => $policy->organization_id,
                    ...$attributes,
                    'effective_from' => $today,
                    'effective_to' => null,
                    'created_by' => $request->user()->id,
                ]);
            }

            $policy->fill($attributes);
            $policy->created_by ??= $request->user()->id;
            $policy->save();

            return $policy;
        });

        $this->audit->log('overtime_policy.updated', $saved, $old, $attributes);

        // §73-C: recalculation runs when the policy version for a date changes.
        $this->recalculateOrg((int) $organization->id, $today);

        return redirect()
            ->route('overtime-policy.edit')
            ->with('status', 'Overtime policy saved. Changes apply from today; past dates keep their original policy.');
    }

    private function currentPolicy(int $organizationId): OvertimePolicy
    {
        return OvertimePolicy::effectiveFor($organizationId, now()->toDateString())
            ?? new OvertimePolicy([
                'organization_id' => $organizationId,
                ...OvertimePolicy::defaults(),
            ]);
    }

    private function recalculateOrg(int $organizationId, string $date): void
    {
        Employee::withoutGlobalScopes()
            ->where('organization_id', $organizationId)
            ->where('status', 'active')
            ->cursor()
            ->each(fn (Employee $employee) => $this->overtime->recalculate($employee, $date));
    }
}
