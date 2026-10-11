<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Organization;
use App\Services\AuditLogger;
use App\Services\RiskService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Scoring & risk settings (§15): score weights, status thresholds/labels,
 * the weekly late-risk rule and the optional [OT] overwork notice. Stored
 * flat in organization.settings — nothing here is hardcoded.
 */
class ScoringSettingsController extends Controller
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly RiskService $risk,
    ) {}

    public function edit(Request $request): View
    {
        Gate::authorize('settings.manage');

        /** @var Organization $organization */
        $organization = $request->user()->organization;
        $config = $this->risk->config($organization);

        return view('scoring.settings', compact('organization', 'config'));
    }

    public function update(Request $request): RedirectResponse
    {
        Gate::authorize('settings.manage');

        /** @var Organization $organization */
        $organization = $request->user()->organization;

        $bool = ['nullable', Rule::in(['0', '1'])];
        $recipientRule = ['required', 'array', 'min:1'];

        $data = $request->validate([
            'score_weight_attendance' => ['required', 'numeric', 'min:0', 'max:100'],
            'score_weight_punctuality' => ['required', 'numeric', 'min:0', 'max:100'],
            'score_weight_working_hours' => ['required', 'numeric', 'min:0', 'max:100'],
            'score_weight_leave' => ['required', 'numeric', 'min:0', 'max:100'],

            'risk_bands' => ['required', 'array', 'size:4'],
            'risk_bands.*.min' => ['required', 'integer', 'min:0', 'max:100'],
            'risk_bands.*.label' => ['required', 'string', 'max:40'],

            'late_risk_enabled' => $bool,
            'late_risk_threshold' => ['required', 'integer', 'min:1', 'max:30'],
            'late_risk_period' => ['required', Rule::in(RiskService::PERIODS)],
            'late_risk_level' => ['required', 'string', 'max:40'],
            'late_risk_recipients' => $recipientRule,
            'late_risk_recipients.*' => [Rule::in(RiskService::RECIPIENTS)],
            'late_risk_suppress' => $bool,

            'ot_risk_enabled' => $bool,
            'ot_risk_weekly_minutes' => ['required', 'integer', 'min:60', 'max:10080'],
            'ot_risk_monthly_minutes' => ['nullable', 'integer', 'min:60', 'max:43200'],
            'ot_risk_streak_days' => ['nullable', 'integer', 'min:2', 'max:31'],
            'ot_risk_level' => ['required', 'string', 'max:40'],
            'ot_risk_recipients' => $recipientRule,
            'ot_risk_recipients.*' => [Rule::in(RiskService::RECIPIENTS)],
            'ot_risk_suppress' => $bool,
        ]);

        $bands = [];
        foreach ($data['risk_bands'] as $index => $row) {
            $bands[] = ['min' => (int) $row['min'], 'label' => trim($row['label'])];
        }

        // Bands must descend so each score falls into exactly one status.
        $errors = [];
        foreach ($bands as $index => $band) {
            if ($index > 0 && $band['min'] >= $bands[$index - 1]['min']) {
                $errors["risk_bands.{$index}.min"] = 'Each threshold must be lower than the one above it.';
            }
        }
        if ($bands[3]['min'] !== 0) {
            $errors['risk_bands.3.min'] = 'The lowest band must start at 0.';
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $changes = [
            'score_weight_attendance' => (float) $data['score_weight_attendance'],
            'score_weight_punctuality' => (float) $data['score_weight_punctuality'],
            'score_weight_working_hours' => (float) $data['score_weight_working_hours'],
            'score_weight_leave' => (float) $data['score_weight_leave'],
            'risk_bands' => $bands,
            'late_risk_enabled' => ($data['late_risk_enabled'] ?? '0') === '1',
            'late_risk_threshold' => (int) $data['late_risk_threshold'],
            'late_risk_period' => $data['late_risk_period'],
            'late_risk_level' => trim($data['late_risk_level']),
            'late_risk_recipients' => array_values(array_unique($data['late_risk_recipients'])),
            'late_risk_suppress' => ($data['late_risk_suppress'] ?? '0') === '1',
            'ot_risk_enabled' => ($data['ot_risk_enabled'] ?? '0') === '1',
            'ot_risk_weekly_minutes' => (int) $data['ot_risk_weekly_minutes'],
            'ot_risk_monthly_minutes' => $data['ot_risk_monthly_minutes'] ?? null,
            'ot_risk_streak_days' => $data['ot_risk_streak_days'] ?? null,
            'ot_risk_level' => trim($data['ot_risk_level']),
            'ot_risk_recipients' => array_values(array_unique($data['ot_risk_recipients'])),
            'ot_risk_suppress' => ($data['ot_risk_suppress'] ?? '0') === '1',
        ];

        // Old values resolve against the effective config (stored settings
        // over defaults) so a first save never logs phantom null diffs.
        $previous = $this->risk->config($organization);
        $old = [];
        foreach (array_keys($changes) as $key) {
            $old[$key] = $previous[$key] ?? null;
        }

        $organization->update([
            'settings' => array_merge($organization->settings ?? [], $changes),
        ]);

        $this->audit->log('scoring_settings.updated', $organization, $old, $changes);

        return redirect()
            ->route('scoring-settings.edit')
            ->with('status', 'Scoring & risk settings saved.');
    }
}
