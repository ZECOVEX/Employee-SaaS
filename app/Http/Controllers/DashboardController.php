<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\DailyAttendance;
use App\Models\Department;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Models\WorkSchedule;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function admin(Request $request): View
    {
        Gate::authorize('dashboard.admin');

        $user = $request->user();
        $orgId = $user->organization_id;
        $timezone = $user->organization?->timezone ?? 'UTC';
        $today = now($timezone);

        $stats = [
            'total_employees' => Employee::where('organization_id', $orgId)->count(),
            'active_employees' => Employee::where('organization_id', $orgId)->where('status', 'active')->count(),
            'departments' => Department::where('organization_id', $orgId)->count(),
            'users' => User::where('organization_id', $orgId)->count(),
        ];

        $todayRecords = DailyAttendance::where('organization_id', $orgId)
            ->where('date', $today->toDateString())
            ->get(['status', 'total_work_minutes', 'late_minutes']);

        $stats['present_today'] = $todayRecords
            ->whereIn('status', ['PRESENT', 'LATE', 'HALF_DAY', 'REMOTE'])->count();
        $stats['late_today'] = $todayRecords
            ->filter(fn ($r) => $r->late_minutes > 0 || $r->status === 'LATE')->count();
        $stats['absent_today'] = $todayRecords->where('status', 'ABSENT')->count();
        $stats['on_leave_today'] = LeaveRequest::where('organization_id', $orgId)
            ->where('status', LeaveRequest::APPROVED)
            ->whereDate('start_date', '<=', $today->toDateString())
            ->whereDate('end_date', '>=', $today->toDateString())
            ->count();

        $thirtyDaysAgo = $today->copy()->subDays(30)->toDateString();
        $avgMinutes = DailyAttendance::where('organization_id', $orgId)
            ->whereBetween('date', [$thirtyDaysAgo, $today->toDateString()])
            ->whereIn('status', ['PRESENT', 'LATE', 'HALF_DAY', 'REMOTE'])
            ->avg('total_work_minutes');
        $stats['avg_attendance_hours'] = $avgMinutes ? round($avgMinutes / 60, 1) : 0.0;

        $recentAudit = AuditLog::with('actor:id,name')
            ->where('organization_id', $orgId)
            ->orderByDesc('id')
            ->limit(8)
            ->get();

        return view('dashboard.admin', compact('stats', 'recentAudit'));
    }

    public function employee(Request $request): View
    {
        Gate::authorize('dashboard.employee');

        $user = $request->user();
        $timezone = $user->organization?->timezone ?? 'UTC';
        $employee = $user->employee()->with(['department:id,name', 'position:id,title', 'manager:id,name'])->first();

        $month = now($timezone);
        if (preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/', $request->string('month')->toString(), $m)) {
            $month = Carbon::create((int) $m[1], (int) $m[2], 1, 0, 0, 0, $timezone);
        }

        $monthStart = $month->copy()->startOfMonth();
        $monthEnd = $month->copy()->endOfMonth();

        $records = collect();
        $monthStats = [];
        $recent = collect();
        $weekMinutes = 0;
        $avgArrival = null;
        $avgDeparture = null;
        $leaveBalance = ['total' => 0, 'used' => 0, 'remaining' => 0];
        $schedule = null;
        $today = now($timezone);

        if ($employee) {
            $schedule = WorkSchedule::effectiveFor($employee->organization_id, $today->toDateString());

            $records = DailyAttendance::where('employee_id', $employee->id)
                ->whereBetween('date', [$monthStart->toDateString(), $monthEnd->toDateString()])
                ->orderBy('date')
                ->get()
                ->keyBy(fn ($r) => $r->date->toDateString());

            $monthStats = [
                'present' => $records->filter(fn ($r) => in_array($r->status, ['PRESENT', 'LATE', 'HALF_DAY', 'REMOTE'], true))->count(),
                'late' => $records->filter(fn ($r) => $r->late_minutes > 0 || $r->status === 'LATE')->count(),
                'absent' => $records->filter(fn ($r) => $r->status === 'ABSENT')->count(),
                'leave' => $records->filter(fn ($r) => $r->status === 'LEAVE')->count(),
                'worked_minutes' => (int) $records->sum('total_work_minutes'),
            ];

            $weekStart = $today->copy()->startOfWeek();
            $weekEnd = $today->copy()->endOfWeek();
            $weekMinutes = (int) DailyAttendance::where('employee_id', $employee->id)
                ->whereBetween('date', [$weekStart->toDateString(), $weekEnd->toDateString()])
                ->sum('total_work_minutes');

            $arrivals = $records->filter(fn ($r) => $r->first_check_in !== null);
            $departures = $records->filter(fn ($r) => $r->last_check_out !== null);

            $avgArrival = $arrivals->isEmpty() ? null : (int) round($arrivals->avg(
                fn ($r) => ((int) $r->first_check_in->format('H')) * 60 + (int) $r->first_check_in->format('i'),
            ));
            $avgDeparture = $departures->isEmpty() ? null : (int) round($departures->avg(
                fn ($r) => ((int) $r->last_check_out->format('H')) * 60 + (int) $r->last_check_out->format('i'),
            ));

            $recent = DailyAttendance::where('employee_id', $employee->id)
                ->orderByDesc('date')
                ->limit(7)
                ->get();

            $balance = LeaveBalance::where('employee_id', $employee->id)
                ->where('year', (int) $today->year)
                ->get(['total_days', 'used_days']);

            $leaveBalance = [
                'total' => (int) $balance->sum('total_days'),
                'used' => (int) $balance->sum('used_days'),
                'remaining' => (int) $balance->sum('total_days') - (int) $balance->sum('used_days'),
            ];
        }

        return view('dashboard.employee', compact(
            'employee', 'user', 'records', 'monthStats', 'recent', 'weekMinutes',
            'avgArrival', 'avgDeparture', 'leaveBalance', 'schedule', 'month', 'today',
        ));
    }
}
