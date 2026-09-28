<?php

namespace App\Http\Controllers;

use App\Models\WorkSchedule;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ScheduleController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function edit(Request $request): View
    {
        Gate::authorize('settings.manage');

        $schedule = $this->currentSchedule($request);

        return view('schedule.edit', compact('schedule'));
    }

    public function update(Request $request): RedirectResponse
    {
        Gate::authorize('settings.manage');

        $schedule = $this->currentSchedule($request);

        $data = $request->validate([
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i', 'after:start_time'],
            'grace_minutes' => ['required', 'integer', 'min:0', 'max:120'],
            'break_start' => ['nullable', 'date_format:H:i'],
            'break_end' => ['nullable', 'date_format:H:i', 'required_if:break_start,*', 'after:break_start'],
            'work_days' => ['required', 'array', 'min:1'],
            'work_days.*' => [Rule::in(['1', '2', '3', '4', '5', '6', '7'])],
        ], [
            'break_end.required_if' => 'Break end is required when break start is set.',
        ]);

        $normalized = [
            'start_time' => $data['start_time'].':00',
            'end_time' => $data['end_time'].':00',
            'grace_minutes' => (int) $data['grace_minutes'],
            'break_start' => isset($data['break_start']) && $data['break_start'] !== ''
                ? $data['break_start'].':00' : null,
            'break_end' => isset($data['break_end']) && $data['break_end'] !== ''
                ? $data['break_end'].':00' : null,
            'work_days' => array_map('intval', $data['work_days']),
        ];

        $old = $schedule->only([
            'start_time', 'end_time', 'grace_minutes', 'break_start', 'break_end', 'work_days',
        ]);

        if ($this->isUnchanged($schedule, $normalized)) {
            return redirect()
                ->route('schedule.edit')
                ->with('status', 'Working hours unchanged.');
        }

        $timezone = $request->user()->organization?->timezone ?? 'UTC';

        DB::transaction(function () use ($schedule, $normalized, $timezone) {
            $today = now($timezone)->toDateString();
            $startedToday = $schedule->effective_from?->toDateString() === $today;

            if ($schedule->exists && ! $startedToday) {
                // Effective dating (§11): close the current version so past days
                // keep the schedule they were derived with, then open a new one.
                $schedule->update([
                    'effective_to' => now($timezone)->subDay()->toDateString(),
                    'is_default' => false,
                ]);

                WorkSchedule::withoutGlobalScopes()->create([
                    'organization_id' => $schedule->organization_id,
                    'name' => $schedule->name,
                    ...$normalized,
                    'is_default' => true,
                    'effective_from' => $today,
                    'effective_to' => null,
                ]);
            } else {
                $schedule->update($normalized);
            }
        });

        $this->audit->log('schedule.updated', $schedule, $old, $normalized);

        return redirect()
            ->route('schedule.edit')
            ->with('status', 'Working hours saved. Changes apply from today; past days keep their original schedule.');
    }

    private function currentSchedule(Request $request): WorkSchedule
    {
        return WorkSchedule::where('is_default', true)->first()
            ?? WorkSchedule::create([
                'organization_id' => $request->user()->organization_id,
                'name' => 'Standard',
                'is_default' => true,
            ]);
    }

    /**
     * @param  array<string, mixed>  $normalized
     */
    private function isUnchanged(WorkSchedule $schedule, array $normalized): bool
    {
        $newDays = $normalized['work_days'];
        $oldDays = $schedule->workDayNumbers();
        sort($newDays);
        sort($oldDays);

        return $schedule->start_time === $normalized['start_time']
            && $schedule->end_time === $normalized['end_time']
            && (int) $schedule->grace_minutes === $normalized['grace_minutes']
            && $schedule->break_start === $normalized['break_start']
            && $schedule->break_end === $normalized['break_end']
            && $newDays === $oldDays;
    }
}
