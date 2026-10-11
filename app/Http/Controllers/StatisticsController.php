<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\DailyAttendance;
use App\Models\LeaveBalance;
use App\Models\OvertimeRecord;
use App\Models\RiskEvent;
use App\Services\OvertimeService;
use App\Services\ReportService;
use App\Services\RiskService;
use App\Services\SalaryCalculator;
use App\Services\ScoreService;
use Carbon\Carbon;
use Illuminate\Http\Request;

class StatisticsController extends Controller
{
    /**
     * Employee's own statistics (§39): monthly overview, salary progress,
     * overtime, weekly attendance, risk & notifications and the score
     * breakdown. Month and week filters; every section reads the same
     * source-of-truth attendance/derived records as the rest of the system.
     *
     * No permission middleware: every employee may see their own numbers.
     * Users without an employee profile get 403.
     */
    public function index(
        Request $request,
        SalaryCalculator $calculator,
        ScoreService $scoring,
        RiskService $risk,
        OvertimeService $overtime,
        ReportService $reports,
    ) {
        $user = $request->user();
        abort_unless((bool) $user->employee, 403);
        $employee = $user->employee;

        $validated = $request->validate([
            'month' => ['nullable', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            'week' => ['nullable', 'date'],
        ], [
            'month.regex' => 'The month must be in YYYY-MM format.',
        ]);

        $organization = $user->organization;
        $tz = $organization?->timezone ?? config('app.timezone');
        $today = Carbon::now($tz);
        $currentMonth = $today->format('Y-m');
        $month = $validated['month'] ?? $currentMonth;

        $monthStart = Carbon::createFromFormat('Y-m-d', $month.'-01', $tz)->startOfMonth();
        $monthEnd = $monthStart->copy()->endOfMonth();
        $previousMonth = $monthStart->copy()->subMonthNoOverflow()->format('Y-m');
        $nextMonth = $monthStart->copy()->addMonthNoOverflow()->format('Y-m');

        // §39 week filter — Monday-anchored, defaults to the current week.
        $weekStart = ($validated['week'] ?? null)
            ? Carbon::parse($validated['week'], $tz)->startOfWeek()
            : $today->copy()->startOfWeek();
        $weekEnd = $weekStart->copy()->endOfWeek();

        $stats = $calculator->estimate($employee, $month);
        $score = $scoring->compute($employee, $month);

        // §39 MONTHLY OVERVIEW + WEEKLY ATTENDANCE (§41 range aggregates).
        $monthOverview = $reports->rangeTotals(
            (int) $employee->organization_id,
            $employee->id,
            $monthStart->toDateString(),
            $monthEnd->toDateString(),
        );
        $weekTotals = $reports->rangeTotals(
            (int) $employee->organization_id,
            $employee->id,
            $weekStart->toDateString(),
            $weekEnd->toDateString(),
        );

        $monthRows = DailyAttendance::where('employee_id', $employee->id)
            ->whereBetween('date', [$monthStart->toDateString(), $monthEnd->toDateString()])
            ->get(['date', 'first_check_in', 'last_check_out']);
        $arrivals = $monthRows->filter(fn ($row) => $row->first_check_in !== null);
        $departures = $monthRows->filter(fn ($row) => $row->last_check_out !== null);
        $avgArrival = $arrivals->isEmpty() ? null : (int) round($arrivals->avg(
            fn ($row) => ((int) $row->first_check_in->format('H')) * 60 + (int) $row->first_check_in->format('i'),
        ));
        $avgDeparture = $departures->isEmpty() ? null : (int) round($departures->avg(
            fn ($row) => ((int) $row->last_check_out->format('H')) * 60 + (int) $row->last_check_out->format('i'),
        ));

        $balance = LeaveBalance::where('employee_id', $employee->id)
            ->where('year', (int) $today->year)
            ->get(['total_days', 'used_days']);
        $remainingLeave = (int) $balance->sum('total_days') - (int) $balance->sum('used_days');
        $totalLeave = (int) $balance->sum('total_days');

        // §39 OVERTIME / EXTRA TIME — month aggregates + daily detail rows.
        $monthOt = $overtime->summaryForRange($employee, $monthStart->toDateString(), $monthEnd->toDateString());
        $weekOt = $overtime->summaryForRange($employee, $weekStart->toDateString(), $weekEnd->toDateString());
        $otRows = OvertimeRecord::with(['approvals.approver:id,name'])
            ->where('employee_id', $employee->id)
            ->whereBetween('work_date', [$monthStart->toDateString(), $monthEnd->toDateString()])
            ->orderBy('work_date')
            ->get()
            ->filter(fn (OvertimeRecord $record) => $record->countable_minutes > 0)
            ->values();
        $otDaily = DailyAttendance::where('employee_id', $employee->id)
            ->whereBetween('date', [$monthStart->toDateString(), $monthEnd->toDateString()])
            ->get()
            ->keyBy(fn (DailyAttendance $row) => $row->date->toDateString());

        // Week salary deduction from this month's breakdown (when covered).
        $weekDeduction = 0.0;
        if ($stats) {
            foreach ($stats['breakdown'] as $day) {
                if ($day['date'] >= $weekStart->toDateString() && $day['date'] <= $weekEnd->toDateString()) {
                    $weekDeduction += (float) $day['deduction'];
                }
            }
        }

        // §39 RISK & NOTIFICATIONS — current week/status, month-independent.
        $riskConfig = $organization ? $risk->config($organization) : null;
        $riskStatus = ($organization && $score)
            ? $risk->statusFor($organization, (float) $score['score'])
            : null;
        $lateThisWeek = DailyAttendance::where('employee_id', $employee->id)
            ->whereBetween('date', [
                $today->copy()->startOfWeek()->toDateString(),
                $today->copy()->endOfWeek()->toDateString(),
            ])
            ->where(fn ($query) => $query->where('late_minutes', '>', 0)->orWhere('status', 'LATE'))
            ->count();
        $riskEvents = RiskEvent::where('employee_id', $employee->id)
            ->where('period_start', '<=', $today->toDateString())
            ->where('period_end', '>=', $today->toDateString())
            ->orderByDesc('id')
            ->get();

        return view('statistics.index', [
            'month' => $month,
            'currentMonth' => $currentMonth,
            'previousMonth' => $previousMonth,
            'nextMonth' => $nextMonth,
            'weekStart' => $weekStart,
            'weekEnd' => $weekEnd,
            'prevWeek' => $weekStart->copy()->subWeek()->toDateString(),
            'nextWeek' => $weekStart->copy()->addWeek()->toDateString(),
            'stats' => $stats,
            'score' => $score,
            'riskConfig' => $riskConfig,
            'riskStatus' => $riskStatus,
            'lateThisWeek' => $lateThisWeek,
            'riskEvents' => $riskEvents,
            'currency' => $organization?->currency() ?? 'BDT',
            'employee' => $employee,
            'monthOverview' => $monthOverview,
            'weekTotals' => $weekTotals,
            'avgArrival' => $avgArrival,
            'avgDeparture' => $avgDeparture,
            'remainingLeave' => $remainingLeave,
            'totalLeave' => $totalLeave,
            'monthOt' => $monthOt,
            'weekOt' => $weekOt,
            'otRows' => $otRows,
            'otDaily' => $otDaily,
            'weekDeduction' => $weekDeduction,
        ]);
    }
}
