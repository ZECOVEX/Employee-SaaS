<?php

namespace App\Http\Controllers;

use App\Models\AttendanceEvent;
use App\Models\DailyAttendance;
use App\Models\Employee;
use App\Notifications\AttendanceCorrected;
use App\Services\AttendanceService;
use App\Services\AuditLogger;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AttendanceController extends Controller
{
    public function __construct(
        private readonly AttendanceService $attendance,
        private readonly AuditLogger $audit,
    ) {}

    public function index(Request $request): View
    {
        Gate::authorize('attendance.view');

        $date = $request->date('date') ?? now($request->user()->organization?->timezone ?? 'UTC')->toDateString();
        $tz = $request->user()->organization?->timezone ?? 'UTC';

        $records = DailyAttendance::with(['employee.user:id,name', 'employee.department:id,name'])
            ->whereDate('date', $date)
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('q'), function ($q) use ($request) {
                $term = $request->string('q')->toString();
                $q->whereHas('employee', fn ($e) => $e->where('employee_code', 'like', "%{$term}%")
                    ->orWhereHas('user', fn ($u) => $u->where('name', 'like', "%{$term}%")));
            })
            ->orderBy('id')
            ->paginate(30)
            ->withQueryString();

        $events = AttendanceEvent::with(['employee.user:id,name', 'terminal:id,name'])
            ->whereNull('superseded_by')
            ->whereDate('occurred_at', $date)
            ->orderByDesc('occurred_at')
            ->limit(40)
            ->get();

        return view('attendance.index', compact('records', 'events', 'date', 'tz'));
    }

    public function create(): View
    {
        Gate::authorize('attendance.manage');

        return view('attendance.correct', [
            'employees' => Employee::with('user:id,name')
                ->where('status', 'active')
                ->orderBy('employee_code')
                ->get(['id', 'employee_code', 'user_id']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('attendance.manage');

        $data = $request->validate([
            'employee_id' => ['required', Rule::exists('employees', 'id')
                ->where('organization_id', $request->user()->organization_id)],
            'event_type' => ['required', Rule::in([
                AttendanceEvent::MANUAL_IN,
                AttendanceEvent::MANUAL_OUT,
                AttendanceEvent::CHECK_IN,
                AttendanceEvent::CHECK_OUT,
            ])],
            'occurred_at' => ['required', 'date'],
            'notes' => ['required', 'string', 'max:500'],
        ]);

        $employee = Employee::findOrFail($data['employee_id']);
        $tz = $employee->organization?->timezone ?? 'UTC';
        $occurredAt = Carbon::parse($data['occurred_at'], $tz);

        $result = $this->attendance->recordManual(
            $employee,
            $data['event_type'],
            $occurredAt,
            $data['notes'],
            $request->user()->id,
        );

        $this->audit->log('attendance.corrected', $result['event'], null, [
            'employee_id' => $employee->id,
            'event_type' => $data['event_type'],
            'occurred_at' => $occurredAt->toIso8601String(),
            'notes' => $data['notes'],
        ]);

        return redirect()
            ->route('attendance.index', ['date' => $occurredAt->toDateString()])
            ->with('status', 'Attendance corrected.');
    }

    /**
     * Enter a full check-in + check-out pair for one day in a single step.
     * Either time may be omitted (only one was missed); both are audited and
     * the day is re-derived from the resulting event set.
     */
    public function storeInOut(Request $request): RedirectResponse
    {
        Gate::authorize('attendance.manage');

        $data = $request->validate([
            'inout_employee_id' => ['required', Rule::exists('employees', 'id')
                ->where('organization_id', $request->user()->organization_id)],
            'inout_date' => ['required', 'date'],
            'inout_check_in' => ['nullable', 'date_format:H:i', 'required_without:inout_check_out'],
            'inout_check_out' => ['nullable', 'date_format:H:i', 'required_without:inout_check_in'],
            'inout_notes' => ['required', 'string', 'max:500'],
        ]);

        if ($data['inout_check_in'] && $data['inout_check_out']
            && strtotime($data['inout_check_out']) <= strtotime($data['inout_check_in'])) {
            throw ValidationException::withMessages([
                'inout_check_out' => 'The check-out time must be after the check-in time.',
            ]);
        }

        $employee = Employee::findOrFail($data['inout_employee_id']);
        $tz = $employee->organization?->timezone ?? 'UTC';

        foreach ([
            'inout_check_in' => AttendanceEvent::MANUAL_IN,
            'inout_check_out' => AttendanceEvent::MANUAL_OUT,
        ] as $field => $eventType) {
            if (! $data[$field]) {
                continue;
            }

            $result = $this->attendance->recordManual(
                $employee,
                $eventType,
                Carbon::parse($data['inout_date'].' '.$data[$field], $tz),
                $data['inout_notes'],
                $request->user()->id,
            );

            $this->audit->log('attendance.corrected', $result['event'], null, [
                'employee_id' => $employee->id,
                'event_type' => $eventType,
                'occurred_at' => $result['event']->occurred_at->format('Y-m-d H:i:s'),
                'notes' => $data['inout_notes'],
                'entry' => 'check_in_check_out',
            ]);
        }

        return redirect()
            ->route('attendance.index', ['date' => $data['inout_date']])
            ->with('status', 'Check-in/check-out recorded — the day has been recalculated.');
    }

    public function editEvent(AttendanceEvent $attendanceEvent): View
    {
        Gate::authorize('attendance.manage');
        $this->guardActive($attendanceEvent);

        $attendanceEvent->load('employee.user:id,name', 'terminal:id,name');

        return view('attendance.edit', [
            'event' => $attendanceEvent,
            'tz' => $attendanceEvent->employee->organization?->timezone ?? 'UTC',
        ]);
    }

    public function updateEvent(Request $request, AttendanceEvent $attendanceEvent): RedirectResponse
    {
        Gate::authorize('attendance.manage');
        $this->guardActive($attendanceEvent);

        $data = $request->validate([
            'event_type' => ['required', Rule::in([
                AttendanceEvent::CHECK_IN,
                AttendanceEvent::CHECK_OUT,
                AttendanceEvent::BREAK_START,
                AttendanceEvent::BREAK_END,
                AttendanceEvent::MANUAL_IN,
                AttendanceEvent::MANUAL_OUT,
            ])],
            'occurred_at' => ['required', 'date'],
            'notes' => ['required', 'string', 'max:500'],
        ]);

        $employee = $attendanceEvent->employee;
        $tz = $employee->organization?->timezone ?? 'UTC';
        $occurredAt = Carbon::parse($data['occurred_at'], $tz);

        $old = [
            'event_type' => $attendanceEvent->event_type,
            'occurred_at' => $attendanceEvent->occurred_at->toIso8601String(),
            'notes' => $attendanceEvent->notes,
        ];

        $replacement = $this->attendance->replaceEvent(
            $attendanceEvent,
            $data['event_type'],
            $occurredAt,
            $data['notes'],
            $request->user()->id,
        );

        $this->audit->log('attendance.event_updated', $replacement, $old, [
            'event_type' => $data['event_type'],
            'occurred_at' => $occurredAt->toIso8601String(),
            'notes' => $data['notes'],
            'replaces_event_id' => $attendanceEvent->id,
            'reason' => $data['notes'],
        ]);

        if ($employee->user_id !== $request->user()->id) {
            $employee->user?->notify(new AttendanceCorrected(
                $employee->id,
                $replacement->occurred_at->toDateString(),
                sprintf(
                    '%s moved from %s to %s',
                    $old['event_type'],
                    Carbon::parse($old['occurred_at'])->format('Y-m-d H:i'),
                    $occurredAt->format('Y-m-d H:i'),
                ),
                $data['notes'],
            ));
        }

        return redirect()
            ->route('attendance.index', ['date' => $replacement->occurred_at->toDateString()])
            ->with('status', 'Entry corrected — the day has been recalculated.');
    }

    public function destroyEvent(Request $request, AttendanceEvent $attendanceEvent): RedirectResponse
    {
        Gate::authorize('attendance.manage');
        $this->guardActive($attendanceEvent);

        $data = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ]);

        $old = [
            'event_type' => $attendanceEvent->event_type,
            'occurred_at' => $attendanceEvent->occurred_at->toIso8601String(),
            'notes' => $attendanceEvent->notes,
            'source' => $attendanceEvent->source,
        ];

        $result = $this->attendance->removeEvent($attendanceEvent);

        $this->audit->log('attendance.event_deleted', null, $old, [
            'employee_id' => $attendanceEvent->employee_id,
            'event_date' => $result['date'],
            'reason' => $data['reason'],
        ]);

        $employee = $attendanceEvent->employee;
        if ($employee->user_id !== $request->user()->id) {
            $employee->user?->notify(new AttendanceCorrected(
                $employee->id,
                $result['date'],
                sprintf('%s entry removed', $old['event_type']),
                $data['reason'],
            ));
        }

        return redirect()
            ->route('attendance.index', ['date' => $result['date']])
            ->with('status', 'Entry removed — the day has been recalculated.');
    }

    /**
     * Archived entries (already corrected or removed) are not editable again.
     */
    private function guardActive(AttendanceEvent $attendanceEvent): void
    {
        if ($attendanceEvent->superseded_by !== null) {
            abort(404, 'This attendance entry has already been corrected.');
        }
    }

    public function employee(Request $request, Employee $employee): View
    {
        $user = $request->user();

        // Self-service: employees may always view their own attendance (§4).
        // Anyone else needs the attendance.view permission.
        if ($user->id !== $employee->user_id) {
            Gate::authorize('attendance.view');
        }

        $month = $request->date('month') ?? now($employee->organization?->timezone ?? 'UTC');

        $records = DailyAttendance::where('employee_id', $employee->id)
            ->whereBetween('date', [
                $month->copy()->startOfMonth()->toDateString(),
                $month->copy()->endOfMonth()->toDateString(),
            ])
            ->orderBy('date')
            ->get();

        return view('attendance.employee', compact('employee', 'records', 'month'));
    }
}
