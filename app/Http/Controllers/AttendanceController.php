<?php

namespace App\Http\Controllers;

use App\Models\AttendanceEvent;
use App\Models\DailyAttendance;
use App\Models\Employee;
use App\Services\AttendanceService;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
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
        $occurredAt = \Carbon\Carbon::parse($data['occurred_at'], $tz);

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

    public function employee(Request $request, Employee $employee): View
    {
        Gate::authorize('attendance.view');
        $user = $request->user();

        if ($user->id !== $employee->user_id && ! $user->canPermission('employees.view')) {
            abort(403);
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
