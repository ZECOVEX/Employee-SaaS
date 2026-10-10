<?php

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

        // §18 score/overtime widgets + §15 admin visibility of risk.
        $organization = $user->organization;
        $monthStart = $today->copy()->startOfMonth()->toDateString();
        $monthEnd = $today->copy()->endOfMonth()->toDateString();
        $todayString = $today->toDateString();

        $scores = [];
        $atRisk = 0;

        if ($organization) {
            $bands = $risk->config($organization)['risk_bands'];
            $lowestMin = (int) end($bands)['min'];
            $currentMonth = $today->format('Y-m');

            Employee::where('organization_id', $orgId)
                ->where('status', 'active')
                ->orderBy('id')
                ->cursor()
                ->each(function (Employee $employee) use ($scoring, $risk, $organization, $currentMonth, $lowestMin, &$scores, &$atRisk) {
                    $score = $scoring->compute($employee, $currentMonth);

                    if ($score === null) {
                        return;
                    }

                    $scores[] = $score['score'];

                    if ($risk->statusFor($organization, (float) $score['score'])['min'] === $lowestMin) {
                        $atRisk++;
                    }
                });
        }

        $stats['avg_score'] = $scores === [] ? null : round(array_sum($scores) / count($scores), 1);
        $stats['at_risk'] = $atRisk;
        $stats['overtime_today'] = (int) OvertimeRecord::where('organization_id', $orgId)
            ->where('work_date', $todayString)
            ->whereIn('status', [
                OvertimeRecord::STATUS_APPROVED,
                OvertimeRecord::STATUS_AUTO_APPROVED,
                OvertimeRecord::STATUS_PENDING,
                OvertimeRecord::STATUS_EXPIRED,
            ])
            ->sum('countable_minutes');
        $stats['pending_overtime'] = OvertimeRecord::where('organization_id', $orgId)
            ->whereIn('status', [OvertimeRecord::STATUS_PENDING, OvertimeRecord::STATUS_EXPIRED])
            ->count();
        $stats['overtime_month'] = (int) OvertimeRecord::where('organization_id', $orgId)
            ->whereBetween('work_date', [$monthStart, $monthEnd])
            ->whereIn('status', [
                OvertimeRecord::STATUS_APPROVED,
                OvertimeRecord::STATUS_AUTO_APPROVED,
                OvertimeRecord::STATUS_PENDING,
                OvertimeRecord::STATUS_EXPIRED,
            ])
            ->sum('countable_minutes');
        $stats['high_risk_alerts'] = RiskEvent::where('organization_id', $orgId)
            ->where('period_start', '<=', $todayString)
            ->where('period_end', '>=', $todayString)
            ->count();

        $recentAudit = AuditLog::with('actor:id,name')
            ->where('organization_id', $orgId)
            ->orderByDesc('id')
            ->limit(8)
            ->get();

        return view('dashboard.admin', compact('stats', 'recentAudit'));
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
                ->get(['total_days', 'used_days']);

            $leaveBalance = [
                'total' => (int) $balance->sum('total_days'),
                'used' => (int) $balance->sum('used_days'),
                'remaining' => (int) $balance->sum('total_days') - (int) $balance->sum('used_days'),
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
            'recentOt', 'dayRows', 'currency', 'weekStart', 'weekEnd',
        ));
    }
}
