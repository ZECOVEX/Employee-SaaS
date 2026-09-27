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

        $schedule = WorkSchedule::where('is_default', true)->first()
            ?? WorkSchedule::create([
                'organization_id' => $request->user()->organization_id,
                'name' => 'Standard',
                'is_default' => true,
            ]);

        return view('schedule.edit', compact('schedule'));
    }

    public function update(Request $request): RedirectResponse
    {
        Gate::authorize('settings.manage');

        $schedule = WorkSchedule::where('is_default', true)->first()
            ?? WorkSchedule::create([
                'organization_id' => $request->user()->organization_id,
                'name' => 'Standard',
                'is_default' => true,
            ]);

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

        $old = $schedule->only(array_keys($data));

        DB::transaction(function () use ($schedule, $data) {
            $schedule->update([
                'start_time' => $data['start_time'].':00',
                'end_time' => $data['end_time'].':00',
                'grace_minutes' => (int) $data['grace_minutes'],
                'break_start' => isset($data['break_start']) && $data['break_start'] !== ''
                    ? $data['break_start'].':00' : null,
                'break_end' => isset($data['break_end']) && $data['break_end'] !== ''
                    ? $data['break_end'].':00' : null,
                'work_days' => array_map('intval', $data['work_days']),
            ]);
        });

        $this->audit->log('schedule.updated', $schedule, $old, $schedule->fresh()->only(array_keys($data)));

        return redirect()
            ->route('schedule.edit')
            ->with('status', 'Working hours saved.');
    }
}
