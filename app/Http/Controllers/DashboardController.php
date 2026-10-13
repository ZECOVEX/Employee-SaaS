<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\DailyAttendance;
use App\Models\Department;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\OvertimeRecord;
use App\Models\RiskEvent;
use App\Models\User;
use App\Models\WorkSchedule;
use App\Services\OvertimeService;
use App\Services\ReportService;
use App\Services\RiskService;
use App\Services\SalaryCalculator;
use App\Services\ScoreService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function admin(Request $request, ScoreService $scoring, RiskService $risk): View
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

        $presentStatuses = ['PRESENT', 'LATE', 'HALF_DAY', 'REMOTE'];
        $todayRecords = DailyAttendance::where('organization_id', $orgId)
            ->where('date', $today->toDateString())
            ->get(['employee_id', 'status', 'total_work_minutes', 'late_minutes', 'first_check_in']);

        $stats['present_today'] = $todayRecords
            ->whereIn('status', $presentStatuses)->count();
        $stats['late_today'] = $todayRecords
            ->filter(fn ($r) => $r->late_minutes > 0 || $r->status === 'LATE')->count();
        $stats['absent_today'] = $todayRecords->where('status', 'ABSENT')->count();

        // Eager-loaded once and reused for the on-leave count *and* the
        // hover drill-down rows (Post-Phase 19).
        $leaveToday = LeaveRequest::where('organization_id', $orgId)
            ->where('status', LeaveRequest::APPROVED)
            ->whereDate('start_date', '<=', $today->toDateString())
            ->whereDate('end_date', '>=', $today->toDateString())
            ->with(['employee.user:id,name', 'leaveType:id,name'])
            ->get();
        $stats['on_leave_today'] = $leaveToday->count();

        $thirtyDaysAgo = $today->copy()->subDays(30)->toDateString();
        $avgMinutes = DailyAttendance::where('organization_id', $orgId)
            ->whereBetween('date', [$thirtyDaysAgo, $today->toDateString()])
            ->whereIn('status', $presentStatuses)
            ->avg('total_work_minutes');
        $stats['avg_attendance_hours'] = $avgMinutes ? round($avgMinutes / 60, 1) : 0.0;

        // §18 score/overtime widgets + §15 admin visibility of risk.
        $organization = $user->organization;
        $monthStart = $today->copy()->startOfMonth()->toDateString();
        $monthEnd = $today->copy()->endOfMonth()->toDateString();
        $todayString = $today->toDateString();

        $scores = [];
        $atRisk = 0;
        $atRiskRows = [];
        $bandCounts = [];
        $bands = [];

        if ($organization) {
            $bands = $risk->config($organization)['risk_bands'];
            $lowestMin = (int) end($bands)['min'];
            $currentMonth = $today->format('Y-m');

            Employee::where('organization_id', $orgId)
                ->where('status', 'active')
                ->with('user:id,name')
                ->orderBy('id')
                ->cursor()
                ->each(function (Employee $employee) use ($scoring, $risk, $organization, $currentMonth, $lowestMin, &$scores, &$atRisk, &$atRiskRows, &$bandCounts) {
                    $score = $scoring->compute($employee, $currentMonth);

                    if ($score === null) {
                        return;
                    }

                    $scores[] = $score['score'];

                    $band = $risk->statusFor($organization, (float) $score['score']);
                    $bandCounts[$band['label']] = ($bandCounts[$band['label']] ?? 0) + 1;

                    if ($band['min'] === $lowestMin) {
                        $atRisk++;

                        if (count($atRiskRows) < 9) {
                            $atRiskRows[] = [
                                'name' => $employee->user->name,
                                'detail' => 'Score '.(int) round($score['score']),
                            ];
                        }
                    }
                });
        }

        $stats['avg_score'] = $scores === [] ? null : round(array_sum($scores) / count($scores), 1);
        $stats['at_risk'] = $atRisk;

        $countableStatuses = [
            OvertimeRecord::STATUS_APPROVED,
            OvertimeRecord::STATUS_AUTO_APPROVED,
            OvertimeRecord::STATUS_PENDING,
            OvertimeRecord::STATUS_EXPIRED,
        ];

        $stats['overtime_today'] = (int) OvertimeRecord::where('organization_id', $orgId)
            ->where('work_date', $todayString)
            ->whereIn('status', $countableStatuses)
            ->sum('countable_minutes');
        $stats['pending_overtime'] = OvertimeRecord::where('organization_id', $orgId)
            ->whereIn('status', [OvertimeRecord::STATUS_PENDING, OvertimeRecord::STATUS_EXPIRED])
            ->count();
        $stats['overtime_month'] = (int) OvertimeRecord::where('organization_id', $orgId)
            ->whereBetween('work_date', [$monthStart, $monthEnd])
            ->whereIn('status', $countableStatuses)
            ->sum('countable_minutes');
        $stats['high_risk_alerts'] = RiskEvent::where('organization_id', $orgId)
            ->where('period_start', '<=', $todayString)
            ->where('period_end', '>=', $todayString)
            ->count();

        // Post-Phase 19 — hover drill-downs: each stat card can reveal the
        // people/records behind its number (rows rendered server-side; the
        // hover-panel component only toggles visibility).
        $drilldowns = [];
        $deptNames = Department::where('organization_id', $orgId)->pluck('name', 'id');
        $statEmployees = Employee::whereIn('id', $todayRecords->pluck('employee_id'))
            ->with('user:id,name')
            ->get(['id', 'user_id', 'department_id'])
            ->keyBy('id');

        $nameOf = fn ($record): string => $statEmployees->get((int) $record->employee_id)?->user->name
            ?? 'Employee #'.$record->employee_id;
        $deptOf = fn ($record): string => $deptNames->get(
            $statEmployees->get((int) $record->employee_id)?->department_id,
        ) ?? '—';

        $drilldowns['present_today'] = [
            'title' => 'Present today',
            'rows' => $todayRecords->whereIn('status', $presentStatuses)->map(fn ($record) => [
                'name' => $nameOf($record),
                'detail' => $record->first_check_in
                    ? 'Arrived '.Carbon::parse($record->first_check_in)->format('H:i')
                    : 'No check-in recorded',
            ])->values()->all(),
        ];

        $drilldowns['late_today'] = [
            'title' => 'Late today',
            'rows' => $todayRecords
                ->filter(fn ($record) => $record->late_minutes > 0 || $record->status === 'LATE')
                ->map(fn ($record) => [
                    'name' => $nameOf($record),
                    'detail' => $record->late_minutes > 0 ? '+'.$record->late_minutes.' min late' : 'Late',
                ])->values()->all(),
        ];

        $drilldowns['absent_today'] = [
            'title' => 'Absent today',
            'rows' => $todayRecords->where('status', 'ABSENT')->map(fn ($record) => [
                'name' => $nameOf($record),
                'detail' => $deptOf($record),
            ])->values()->all(),
        ];

        $drilldowns['on_leave_today'] = [
            'title' => 'On leave today',
            'rows' => $leaveToday->map(fn (LeaveRequest $request) => [
                'name' => $request->employee->user->name,
                'detail' => $request->leaveType->name.' · '.(
                    Carbon::parse($request->start_date)->isSameDay(Carbon::parse($request->end_date))
                        ? Carbon::parse($request->start_date)->format('d M')
                        : Carbon::parse($request->start_date)->format('d M').'–'.Carbon::parse($request->end_date)->format('d M')
                ),
            ])->values()->all(),
        ];

        $dailyAvg = DailyAttendance::where('organization_id', $orgId)
            ->whereBetween('date', [$today->copy()->subDays(6)->toDateString(), $todayString])
            ->whereIn('status', $presentStatuses)
            ->selectRaw('date, avg(total_work_minutes) as avg_minutes')
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        $drilldowns['avg_attendance'] = [
            'title' => 'Daily average (last 7 days)',
            'rows' => $dailyAvg->map(fn ($row) => [
                'name' => Carbon::parse($row->date)->format('D, d M'),
                'detail' => round(((int) $row->getAttribute('avg_minutes')) / 60, 1).'h average',
            ])->values()->all(),
        ];

        $statusCounts = Employee::where('organization_id', $orgId)
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->orderByDesc('total')
            ->get();

        $drilldowns['total_employees'] = [
            'title' => 'By employment status',
            'rows' => $statusCounts->map(fn ($row) => [
                'name' => ucfirst((string) $row->status),
                'detail' => $row->getAttribute('total').' · '.(int) round(((int) $row->getAttribute('total')) / max(1, (int) $stats['total_employees']) * 100).'%',
            ])->values()->all(),
        ];

        $deptBreakdown = Employee::where('organization_id', $orgId)
            ->selectRaw('department_id, status, count(*) as total')
            ->groupBy('department_id', 'status')
            ->get();

        $deptTotals = [];
        $activeByDept = [];

        foreach ($deptBreakdown as $row) {
            $key = (string) ($row->department_id ?? '');
            $deptTotals[$key] = ($deptTotals[$key] ?? 0) + (int) $row->getAttribute('total');

            if ($row->status === 'active') {
                $activeByDept[$key] = (int) $row->getAttribute('total');
            }
        }

        arsort($deptTotals);
        arsort($activeByDept);

        $deptLabel = fn (string $key): string => $key === ''
            ? 'Unassigned'
            : ($deptNames->get((int) $key) ?? 'Department #'.$key);

        $drilldowns['departments'] = [
            'title' => 'Employees per department',
            'rows' => collect($deptTotals)->map(fn ($count, $key) => [
                'name' => $deptLabel((string) $key),
                'detail' => $count.' '.str('employee')->plural((int) $count),
            ])->values()->all(),
        ];

        $drilldowns['active_employees'] = [
            'title' => 'Active employees by department',
            'rows' => collect($activeByDept)->map(fn ($count, $key) => [
                'name' => $deptLabel((string) $key),
                'detail' => $count.' active',
            ])->values()->all(),
        ];

        $recentUsers = User::where('organization_id', $orgId)
            ->orderByDesc('id')
            ->limit(9)
            ->get(['id', 'name', 'email']);

        $drilldowns['users'] = [
            'title' => 'Recently added users',
            'total' => (int) $stats['users'],
            'rows' => $recentUsers->map(fn (User $member) => [
                'name' => $member->name,
                'detail' => $member->email,
            ])->values()->all(),
        ];

        $drilldowns['avg_score'] = [
            'title' => 'Score distribution',
            'rows' => collect($bands)->map(fn (array $band) => [
                'name' => (string) $band['label'],
                'detail' => ($bandCounts[(string) $band['label']] ?? 0).' employees',
            ])->values()->all(),
        ];

        $drilldowns['at_risk'] = [
            'title' => 'At-risk employees',
            'total' => $atRisk,
            'rows' => $atRiskRows,
        ];

        $highRiskEvents = RiskEvent::where('organization_id', $orgId)
            ->where('period_start', '<=', $todayString)
            ->where('period_end', '>=', $todayString)
            ->with('employee.user:id,name')
            ->orderByDesc('id')
            ->limit(9)
            ->get();

        $drilldowns['high_risk_alerts'] = [
            'title' => 'High-risk alerts',
            'total' => (int) $stats['high_risk_alerts'],
            'rows' => $highRiskEvents->map(fn (RiskEvent $event) => [
                'name' => $event->employee->user->name,
                'detail' => $event->typeLabel(),
            ])->values()->all(),
        ];

        $otToday = OvertimeRecord::where('organization_id', $orgId)
            ->where('work_date', $todayString)
            ->whereIn('status', $countableStatuses)
            ->with('employee.user:id,name')
            ->get();

        $drilldowns['overtime_today'] = [
            'title' => 'Overtime today',
            'rows' => $otToday->map(fn (OvertimeRecord $record) => [
                'name' => $record->employee->user->name,
                'detail' => '+'.round($record->countable_minutes / 60, 1).'h',
            ])->values()->all(),
        ];

        $otPending = OvertimeRecord::where('organization_id', $orgId)
            ->whereIn('status', [OvertimeRecord::STATUS_PENDING, OvertimeRecord::STATUS_EXPIRED])
            ->with('employee.user:id,name')
            ->orderByDesc('work_date')
            ->limit(9)
            ->get();

        $drilldowns['pending_overtime'] = [
            'title' => 'Pending overtime approvals',
            'total' => (int) $stats['pending_overtime'],
            'rows' => $otPending->map(fn (OvertimeRecord $record) => [
                'name' => $record->employee->user->name,
                'detail' => '+'.round($record->countable_minutes / 60, 1).'h · '.Carbon::parse($record->work_date)->format('d M'),
            ])->values()->all(),
        ];

        $otMonthRows = OvertimeRecord::where('organization_id', $orgId)
            ->whereBetween('work_date', [$monthStart, $monthEnd])
            ->whereIn('status', $countableStatuses)
            ->with('employee.user:id,name')
            ->orderByDesc('countable_minutes')
            ->limit(9)
            ->get();

        $drilldowns['overtime_month'] = [
            'title' => 'Top overtime this month',
            'total' => OvertimeRecord::where('organization_id', $orgId)
                ->whereBetween('work_date', [$monthStart, $monthEnd])
                ->whereIn('status', $countableStatuses)
                ->count(),
            'rows' => $otMonthRows->map(fn (OvertimeRecord $record) => [
                'name' => $record->employee->user->name,
                'detail' => '+'.round($record->countable_minutes / 60, 1).'h',
            ])->values()->all(),
        ];

        $recentAudit = AuditLog::with('actor:id,name')
            ->where('organization_id', $orgId)
            ->orderByDesc('id')
            ->limit(8)
            ->get();

        return view('dashboard.admin', compact('stats', 'recentAudit', 'drilldowns'));
    }

    public function employee(
        Request $request,
        ScoreService $scoring,
        RiskService $risk,
        OvertimeService $overtime,
        ReportService $reports,
        SalaryCalculator $calculator,
    ): View {
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
        $drilldowns = [];
        $weekMinutes = 0;
        $avgArrival = null;
        $avgDeparture = null;
        $leaveBalance = ['total' => 0, 'used' => 0, 'remaining' => 0];
        $schedule = null;
        $riskEvents = collect();
        $today = now($timezone);
        $weekStart = $today->copy()->startOfWeek();
        $weekEnd = $today->copy()->endOfWeek();
        $organization = $user->organization;
        $currency = $organization?->currency() ?? 'BDT';
        $scoreData = null;
        $scoreBand = null;
        $monthOt = null;
        $todayOt = null;
        $weekOt = null;
        $weekTotals = null;
        $weekLateDeduction = 0.0;
        $showOt = false;
        $showOtPay = false;
        $otByDay = collect();
        $recentOt = collect();
        $dayRows = [];

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
                ->with('leaveType:id,name')
                ->get(['leave_type_id', 'total_days', 'used_days']);

            $leaveBalance = [
                'total' => (int) $balance->sum('total_days'),
                'used' => (int) $balance->sum('used_days'),
                'remaining' => (int) $balance->sum('total_days') - (int) $balance->sum('used_days'),
            ];

            // Post-Phase 19 — hover drill-downs for the month tiles: which
            // days (and arrival times) make up each number.
            $drilldowns['present'] = [
                'title' => 'Present days',
                'rows' => $records
                    ->filter(fn ($r) => in_array($r->status, ['PRESENT', 'LATE', 'HALF_DAY', 'REMOTE'], true))
                    ->sortKeys()
                    ->reverse()
                    ->map(fn ($r) => [
                        'name' => Carbon::parse($r->date)->format('D, d M'),
                        'detail' => $r->first_check_in
                            ? 'Arrived '.Carbon::parse($r->first_check_in)->format('H:i')
                            : 'Present',
                    ])->values()->all(),
            ];

            $drilldowns['late'] = [
                'title' => 'Late arrivals',
                'rows' => $records
                    ->filter(fn ($r) => $r->late_minutes > 0 || $r->status === 'LATE')
                    ->sortKeys()
                    ->reverse()
                    ->map(fn ($r) => [
                        'name' => Carbon::parse($r->date)->format('D, d M'),
                        'detail' => $r->late_minutes > 0 ? '+'.$r->late_minutes.' min late' : 'Late',
                    ])->values()->all(),
            ];

            $drilldowns['absent'] = [
                'title' => 'Absent days',
                'rows' => $records
                    ->filter(fn ($r) => $r->status === 'ABSENT')
                    ->sortKeys()
                    ->reverse()
                    ->map(fn ($r) => [
                        'name' => Carbon::parse($r->date)->format('D, d M'),
                        'detail' => 'No check-in',
                    ])->values()->all(),
            ];

            $drilldowns['leave'] = [
                'title' => 'Leave days',
                'rows' => $records
                    ->filter(fn ($r) => $r->status === 'LEAVE')
                    ->sortKeys()
                    ->reverse()
                    ->map(fn ($r) => [
                        'name' => Carbon::parse($r->date)->format('D, d M'),
                        'detail' => 'On leave',
                    ])->values()->all(),
            ];

            $drilldowns['balance'] = [
                'title' => 'Leave balance by type',
                'rows' => $balance->map(fn ($row) => [
                    'name' => $row->leaveType->name ?? 'Leave',
                    'detail' => 'used '.$row->used_days.' of '.$row->total_days.' d',
                ])->values()->all(),
            ];

            // §39: the dashboard surfaces high-risk warnings when the
            // configured weekly late threshold (or an [OT] limit) is hit.
            $riskEvents = RiskEvent::where('employee_id', $employee->id)
                ->where('period_start', '<=', $today->toDateString())
                ->where('period_end', '>=', $today->toDateString())
                ->orderByDesc('id')
                ->limit(5)
                ->get();

            // §39 main score card + working hours vs expected (overtime stays
            // out of the worked figure — it is reported separately).
            $scoreData = $scoring->compute($employee, $month->format('Y-m'));
            $scoreBand = ($scoreData && $organization)
                ? $risk->statusFor($organization, (float) $scoreData['score'])
                : null;

            $monthTotals = $reports->rangeTotals(
                (int) $employee->organization_id,
                $employee->id,
                $monthStart->toDateString(),
                $monthEnd->toDateString(),
            );
            $monthStats['expected_minutes'] = $monthTotals['expected_minutes'];
            $monthStats['worked_display_minutes'] = $monthTotals['worked_capped_minutes'];

            // [OT] card + today's record + per-day overlays for the calendar,
            // recent-attendance table and day tooltips.
            $monthOt = $overtime->summaryForRange($employee, $monthStart->toDateString(), $monthEnd->toDateString());
            $showOt = (bool) $monthOt['eligible'];
            $showOtPay = $showOt && (bool) $monthOt['show_pay'];

            $todayOt = OvertimeRecord::query()
                ->where('employee_id', $employee->id)
                ->where('work_date', $today->toDateString())
                ->first();

            $weekOt = $overtime->summaryForRange($employee, $weekStart->toDateString(), $weekEnd->toDateString());
            $weekTotals = $reports->rangeTotals(
                (int) $employee->organization_id,
                $employee->id,
                $weekStart->toDateString(),
                $weekEnd->toDateString(),
            );

            $otByDay = ($showOt && (bool) $monthOt['show_hours'])
                ? OvertimeRecord::query()
                    ->where('employee_id', $employee->id)
                    ->whereBetween('work_date', [$monthStart->toDateString(), $monthEnd->toDateString()])
                    ->with('approvals:id,overtime_record_id,decision,reason,decided_at')
                    ->get()
                    ->keyBy(fn (OvertimeRecord $record) => $record->work_date->toDateString())
                : collect();

            $recentOt = ($showOt && (bool) $monthOt['show_hours'] && $recent->isNotEmpty())
                ? OvertimeRecord::query()
                    ->where('employee_id', $employee->id)
                    ->whereBetween('work_date', [
                        $recent->last()->date->toDateString(),
                        $recent->first()->date->toDateString(),
                    ])
                    ->get()
                    ->keyBy(fn (OvertimeRecord $record) => $record->work_date->toDateString())
                : collect();

            // Day-level salary impact for the calendar tooltips and the
            // weekly late deduction line (§39).
            $estimate = $calculator->estimate($employee, $month->format('Y-m'));

            if ($estimate !== null) {
                foreach ($estimate['breakdown'] as $row) {
                    $dayRows[$row['date']] = $row;

                    if ($row['date'] >= $weekStart->toDateString()
                        && $row['date'] <= $weekEnd->toDateString()
                        && $row['late_minutes'] > 0) {
                        $weekLateDeduction += (float) $row['deduction'];
                    }
                }
            }

            $weekLateDeduction = round($weekLateDeduction, 2);
        }

        return view('dashboard.employee', compact(
            'employee', 'user', 'records', 'monthStats', 'recent', 'weekMinutes',
            'avgArrival', 'avgDeparture', 'leaveBalance', 'schedule', 'month', 'today',
            'riskEvents', 'scoreData', 'scoreBand', 'monthOt', 'todayOt', 'weekOt',
            'weekTotals', 'weekLateDeduction', 'showOt', 'showOtPay', 'otByDay',
            'recentOt', 'dayRows', 'currency', 'weekStart', 'weekEnd', 'drilldowns',
        ));
    }
}
