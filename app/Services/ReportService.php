<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\DailyAttendance;
use App\Models\Employee;
use App\Models\EmployeeMonthlyStatus;
use App\Models\Holiday;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\OvertimeRecord;
use App\Models\Payslip;
use App\Models\RiskEvent;
use App\Models\WorkSchedule;
use Carbon\Carbon;

/**
 * Aggregations behind the reports and the analytics dashboard.
 */
class ReportService
{
    /** @var array<int, array<string, array<string, true>>> request-level ledger cache */
    private array $ledgerCache = [];

    /**
     * Scheduled workdays of a month (weekday âˆ§ non-holiday): date => true.
     *
     * @return array<string, true>
     */
    public function ledger(int $organizationId, string $month): array
    {
        if (isset($this->ledgerCache[$organizationId][$month])) {
            return $this->ledgerCache[$organizationId][$month];
        }

        $start = Carbon::createFromFormat('!Y-m-d', $month.'-01');
        $end = $start->copy()->endOfMonth();

        $holidays = [];
        Holiday::withoutGlobalScopes()
            ->where('organization_id', $organizationId)
            ->whereDate('date', '>=', $start->toDateString())
            ->whereDate('date', '<=', $end->toDateString())
            ->get()
            ->each(function (Holiday $holiday) use (&$holidays) {
                $holidays[$holiday->date->toDateString()] = true;
            });

        $ledger = [];
        $cursor = $start->copy();
        while ($cursor->lte($end)) {
            $date = $cursor->toDateString();
            $schedule = WorkSchedule::effectiveFor($organizationId, $date);
            $isWorkDay = in_array((int) $cursor->dayOfWeekIso, $schedule?->workDayNumbers() ?? [1, 2, 3, 4, 5], true);

            if ($isWorkDay && ! isset($holidays[$date])) {
                $ledger[$date] = true;
            }
            $cursor->addDay();
        }

        return $this->ledgerCache[$organizationId][$month] = $ledger;
    }

    /**
     * One employee's monthly report row (Â§41 Monthly Report). Absent covers
     * both stamped ABSENT rows and scheduled days with no data at all.
     *
     * @param  array<string, true>  $ledger
     * @return array{present: int, late: int, half: int, leave: int, absent: int, worked_minutes: int, overtime_minutes: int, late_minutes: int, scheduled_days: int}
     */
    public function monthlyTotals(int $organizationId, int $employeeId, string $month, array $ledger): array
    {
        $start = Carbon::createFromFormat('!Y-m-d', $month.'-01');
        $end = $start->copy()->endOfMonth();

        $totals = [
            'present' => 0,
            'late' => 0,
            'half' => 0,
            'leave' => 0,
            'absent' => 0,
            'worked_minutes' => 0,
            'overtime_minutes' => 0,
            'late_minutes' => 0,
            'scheduled_days' => count($ledger),
        ];

        DailyAttendance::withoutGlobalScopes()
            ->where('employee_id', $employeeId)
            ->whereDate('date', '>=', $start->toDateString())
            ->whereDate('date', '<=', $end->toDateString())
            ->get()
            ->each(function (DailyAttendance $row) use (&$totals, $ledger) {
                $totals['worked_minutes'] += $row->total_work_minutes;
                $totals['overtime_minutes'] += $row->overtime_minutes;
                $totals['late_minutes'] += $row->late_minutes;

                $date = $row->date->toDateString();
                if (! isset($ledger[$date])) {
                    return;
                }

                if (in_array($row->status, ['PRESENT', 'REMOTE'], true)) {
                    $totals['present']++;
                } elseif ($row->status === 'LATE') {
                    $totals['present']++;
                    $totals['late']++;
                } elseif ($row->status === 'HALF_DAY') {
                    $totals['half']++;
                } elseif ($row->status === 'LEAVE') {
                    $totals['leave']++;
                }
                // ABSENT rows are covered by the formula below.
            });

        $totals['absent'] = max(
            0,
            $totals['scheduled_days'] - $totals['present'] - $totals['half'] - $totals['leave'],
        );

        return $totals;
    }

    /**
     * Counts + expected/capped-worked minutes for an arbitrary date range
     * (Â§39 month & week filters). Same classification rules as
     * monthlyTotals, but builds its own ledger and tracks expected minutes
     * and per-day capped worked minutes (overtime never inflates "worked"),
     * counting only elapsed scheduled days.
     *
     * @return array{present: int, late: int, half: int, leave: int, absent: int, worked_minutes: int, worked_capped_minutes: int, expected_minutes: int, late_minutes: int, scheduled_days: int}
     */
    public function rangeTotals(int $organizationId, int $employeeId, string $from, string $to): array
    {
        $start = Carbon::createFromFormat('!Y-m-d', $from);
        $end = Carbon::parse($to);
        $today = now()->toDateString();

        $holidays = [];
        Holiday::withoutGlobalScopes()
            ->where('organization_id', $organizationId)
            ->whereDate('date', '>=', $from)
            ->whereDate('date', '<=', $to)
            ->get()
            ->each(function (Holiday $holiday) use (&$holidays) {
                $holidays[$holiday->date->toDateString()] = true;
            });

        $ledger = [];
        $expectedByDate = [];
        $elapsedScheduled = 0;
        $cursor = $start->copy();

        while ($cursor->lte($end)) {
            $date = $cursor->toDateString();
            $schedule = WorkSchedule::effectiveFor($organizationId, $date);
            $isWorkDay = in_array((int) $cursor->dayOfWeekIso, $schedule?->workDayNumbers() ?? [1, 2, 3, 4, 5], true);

            if ($isWorkDay && ! isset($holidays[$date])) {
                $ledger[$date] = true;
                $expectedByDate[$date] = $this->scheduleExpectedMinutes($schedule);

                if ($date <= $today) {
                    $elapsedScheduled++;
                }
            }

            $cursor->addDay();
        }

        $totals = [
            'present' => 0,
            'late' => 0,
            'half' => 0,
            'leave' => 0,
            'absent' => 0,
            'worked_minutes' => 0,
            'worked_capped_minutes' => 0,
            'expected_minutes' => 0,
            'late_minutes' => 0,
            'scheduled_days' => count($ledger),
        ];

        DailyAttendance::withoutGlobalScopes()
            ->where('employee_id', $employeeId)
            ->whereDate('date', '>=', $from)
            ->whereDate('date', '<=', $to)
            ->get()
            ->each(function (DailyAttendance $row) use (&$totals, $ledger, $expectedByDate) {
                $totals['worked_minutes'] += $row->total_work_minutes;
                $totals['late_minutes'] += $row->late_minutes;

                $date = $row->date->toDateString();
                if (isset($expectedByDate[$date])) {
                    $totals['worked_capped_minutes'] += min((int) $row->total_work_minutes, $expectedByDate[$date]);
                }

                if (! isset($ledger[$date])) {
                    return;
                }

                if (in_array($row->status, ['PRESENT', 'REMOTE'], true)) {
                    $totals['present']++;
                } elseif ($row->status === 'LATE') {
                    $totals['present']++;
                    $totals['late']++;
                } elseif ($row->status === 'HALF_DAY') {
                    $totals['half']++;
                } elseif ($row->status === 'LEAVE') {
                    $totals['leave']++;
                }
            });

        foreach ($expectedByDate as $date => $expected) {
            if ($date <= $today) {
                $totals['expected_minutes'] += $expected;
            }
        }

        // Absences only up to today â€” future days are not absent.
        $totals['absent'] = max(
            0,
            $elapsedScheduled - $totals['present'] - $totals['half'] - $totals['leave'],
        );

        return $totals;
    }

    private function scheduleExpectedMinutes(?WorkSchedule $schedule): int
    {
        if (! $schedule) {
            return 480;
        }

        $minutes = ((int) strtotime($schedule->end_time) - (int) strtotime($schedule->start_time)) / 60;

        if ($schedule->break_start && $schedule->break_end) {
            $minutes -= ((int) strtotime($schedule->break_end) - (int) strtotime($schedule->break_start)) / 60;
        }

        return (int) $minutes;
    }

    /**
     * Analytics payload for the dashboard: attendance trend, department
     * comparison, weekday late pattern, payroll progress plus workforce
     * mix, leave analytics, status distribution, workload, scores and risk.
     *
     * @return array<string, mixed>
     */
    public function analytics(int $organizationId): array
    {
        $ledger = $this->ledger($organizationId, Carbon::now()->format('Y-m'));
        $activeEmployees = Employee::withoutGlobalScopes()
            ->where('organization_id', $organizationId)
            ->where('status', 'active')
            ->count();

        return [
            'trend' => $this->attendanceTrend($organizationId, 6, $activeEmployees),
            'departments' => $this->departmentStats($organizationId, $ledger),
            'weekdays' => $this->weekdayLatePattern($organizationId),
            'payroll' => $this->payrollTrend($organizationId, 6),
            'overtime' => $this->overtimeTrend($organizationId, 6),
            'active_employees' => $activeEmployees,
            'workforce' => $this->workforceMix($organizationId),
            'leave' => $this->leaveStats($organizationId),
            'status_distribution' => $this->attendanceStatusDistribution($organizationId),
            'workload' => $this->workloadStats($organizationId, $ledger),
            'scores' => $this->scoreDistribution($organizationId),
            'risk' => $this->riskSummary($organizationId),
        ];
    }

    /**
     * Attendance rate per month for the last N months (% of expected
     * employee-days actually attended).
     *
     * @return list<array{month: string, label: string, rate: float, attended: int, expected: int}>
     */
    private function attendanceTrend(int $organizationId, int $months, int $activeEmployees): array
    {
        $result = [];
        $cursor = Carbon::now()->startOfMonth()->subMonths($months - 1);

        for ($i = 0; $i < $months; $i++) {
            $month = $cursor->format('Y-m');
            $ledger = $this->ledger($organizationId, $month);
            $expected = count($ledger) * max(1, $activeEmployees);
            $attended = 0;

            DailyAttendance::withoutGlobalScopes()
                ->where('organization_id', $organizationId)
                ->whereDate('date', '>=', $cursor->toDateString())
                ->whereDate('date', '<=', $cursor->copy()->endOfMonth()->toDateString())
                ->get(['date', 'status'])
                ->each(function ($row) use (&$attended, $ledger) {
                    if (! isset($ledger[$row->date->toDateString()])) {
                        return;
                    }
                    if (in_array($row->status, ['PRESENT', 'LATE', 'REMOTE', 'HALF_DAY'], true)) {
                        $attended++;
                    }
                });

            $result[] = [
                'month' => $month,
                'label' => $cursor->format('M'),
                'rate' => $expected > 0 ? round($attended / $expected * 100, 1) : 0.0,
                'attended' => $attended,
                'expected' => $expected,
            ];

            $cursor->addMonthNoOverflow();
        }

        return $result;
    }

    /**
     * Current-month attendance rate per department (employees without any
     * rows count as zero attended days â€” they are part of the denominator).
     *
     * @param  array<string, true>  $ledger
     * @return list<array{name: string, employees: int, rate: float, attended: int, late_minutes: int}>
     */
    private function departmentStats(int $organizationId, array $ledger): array
    {
        $members = Employee::withoutGlobalScopes()
            ->where('organization_id', $organizationId)
            ->where('status', 'active')
            ->with('department:id,name')
            ->get(['id', 'department_id']);

        $rows = DailyAttendance::withoutGlobalScopes()
            ->where('organization_id', $organizationId)
            ->whereDate('date', '>=', Carbon::now()->startOfMonth()->toDateString())
            ->whereDate('date', '<=', Carbon::now()->endOfMonth()->toDateString())
            ->get(['employee_id', 'date', 'status', 'late_minutes']);

        $attended = [];
        $lateMinutes = [];
        foreach ($rows as $row) {
            if (! isset($ledger[$row->date->toDateString()])) {
                continue;
            }
            if (in_array($row->status, ['PRESENT', 'LATE', 'REMOTE', 'HALF_DAY'], true)) {
                $deptId = (int) ($members->firstWhere('id', $row->employee_id)?->department_id ?? 0);
                $attended[$deptId] = ($attended[$deptId] ?? 0) + 1;
                $lateMinutes[$deptId] = ($lateMinutes[$deptId] ?? 0) + $row->late_minutes;
            }
        }

        $scheduledDays = count($ledger);
        $stats = [];

        $members->groupBy(fn (Employee $employee) => (int) ($employee->department_id ?? 0))
            ->each(function ($group, $deptId) use (&$stats, $attended, $lateMinutes, $scheduledDays) {
                $employees = $group->count();
                $dept = $group->first()->department;
                $attendedDays = $attended[(int) $deptId] ?? 0;
                $expected = $scheduledDays * $employees;

                $stats[] = [
                    'name' => $dept?->name ?? 'Unassigned',
                    'employees' => $employees,
                    'rate' => $expected > 0 ? round($attendedDays / $expected * 100, 1) : 0.0,
                    'attended' => $attendedDays,
                    'late_minutes' => $lateMinutes[(int) $deptId] ?? 0,
                ];
            });

        usort($stats, fn (array $a, array $b) => $b['rate'] <=> $a['rate']);

        return $stats;
    }

    /**
     * Late arrivals per weekday (1 = Monday â€¦ 7 = Sunday) for the current month.
     *
     * @return list<array{label: string, count: int}>
     */
    private function weekdayLatePattern(int $organizationId): array
    {
        $labels = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
        $counts = array_fill(1, 7, 0);

        DailyAttendance::withoutGlobalScopes()
            ->where('organization_id', $organizationId)
            ->where('status', 'LATE')
            ->whereDate('date', '>=', Carbon::now()->startOfMonth()->toDateString())
            ->whereDate('date', '<=', Carbon::now()->endOfMonth()->toDateString())
            ->get(['date'])
            ->each(function ($row) use (&$counts) {
                $counts[(int) $row->date->dayOfWeekIso]++;
            });

        $result = [];
        foreach ($labels as $index => $label) {
            $result[] = ['label' => $label, 'count' => $counts[$index + 1]];
        }

        return $result;
    }

    /**
     * Finalized net payroll per period for the last N months (active
     * revisions only).
     *
     * @return list<array{period: string, label: string, total: float}>
     */
    private function payrollTrend(int $organizationId, int $months): array
    {
        $from = Carbon::now()->startOfMonth()->subMonths($months - 1)->format('Y-m');

        $totals = Payslip::withoutGlobalScopes()
            ->where('organization_id', $organizationId)
            ->whereNull('superseded_by')
            ->where('period', '>=', $from)
            ->get(['period', 'net_salary'])
            ->groupBy('period')
            ->map(fn ($group) => round((float) $group->sum('net_salary'), 2));

        $result = [];
        $cursor = Carbon::now()->startOfMonth()->subMonths($months - 1);
        for ($i = 0; $i < $months; $i++) {
            $period = $cursor->format('Y-m');
            $result[] = [
                'period' => $period,
                'label' => $cursor->format('M y'),
                'total' => (float) ($totals[$period] ?? 0.0),
            ];
            $cursor->addMonthNoOverflow();
        }

        return $result;
    }

    /**
     * Countable overtime hours per month for the last N months â€” approved
     * and pending split, matching the risk-limit accounting (OT phase).
     *
     * @return list<array{month: string, label: string, hours: float, approved: float, pending: float}>
     */
    private function overtimeTrend(int $organizationId, int $months): array
    {
        $result = [];
        $cursor = Carbon::now()->startOfMonth()->subMonths($months - 1);

        for ($i = 0; $i < $months; $i++) {
            $approved = 0;
            $pending = 0;

            OvertimeRecord::withoutGlobalScopes()
                ->where('organization_id', $organizationId)
                ->whereBetween('work_date', [
                    $cursor->toDateString(),
                    $cursor->copy()->endOfMonth()->toDateString(),
                ])
                ->whereIn('status', [
                    OvertimeRecord::STATUS_APPROVED,
                    OvertimeRecord::STATUS_AUTO_APPROVED,
                    OvertimeRecord::STATUS_PENDING,
                    OvertimeRecord::STATUS_EXPIRED,
                ])
                ->get(['status', 'countable_minutes'])
                ->each(function (OvertimeRecord $record) use (&$approved, &$pending) {
                    if (in_array($record->status, [OvertimeRecord::STATUS_PENDING, OvertimeRecord::STATUS_EXPIRED], true)) {
                        $pending += $record->countable_minutes;
                    } else {
                        $approved += $record->countable_minutes;
                    }
                });

            $result[] = [
                'month' => $cursor->format('Y-m'),
                'label' => $cursor->format('M'),
                'approved' => round($approved / 60, 1),
                'pending' => round($pending / 60, 1),
                'hours' => round(($approved + $pending) / 60, 1),
            ];

            $cursor->addMonthNoOverflow();
        }

        return $result;
    }

    /**
     * Workforce composition: employment-type and status mix plus joiners
     * in the last 30 days.
     *
     * @return array{employment: list<array{label: string, count: int}>, status: list<array{label: string, count: int, tone: string}>, joiners_30d: int, total: int}
     */
    private function workforceMix(int $organizationId): array
    {
        $employees = Employee::withoutGlobalScopes()
            ->where('organization_id', $organizationId)
            ->get(['status', 'employment_type', 'joining_date']);

        $employmentLabels = ['full_time' => 'Full-time', 'part_time' => 'Part-time', 'contract' => 'Contract', 'intern' => 'Intern'];
        $statusLabels = ['active' => ['Active', 'emerald'], 'inactive' => ['Inactive', 'amber'], 'terminated' => ['Terminated', 'red']];
        $employment = [];
        $statuses = [];
        $cutoff = Carbon::now()->subDays(30)->toDateString();
        $joiners = 0;

        foreach ($employees as $employee) {
            $type = (string) $employee->employment_type;
            $employment[$type] = ($employment[$type] ?? 0) + 1;
            $status = (string) $employee->status;
            $statuses[$status] = ($statuses[$status] ?? 0) + 1;

            if (Carbon::parse($employee->joining_date)->toDateString() >= $cutoff) {
                $joiners++;
            }
        }

        $employmentRows = [];
        foreach ($employmentLabels as $key => $label) {
            if (($employment[$key] ?? 0) > 0) {
                $employmentRows[] = ['label' => $label, 'count' => $employment[$key]];
            }
        }
        foreach ($employment as $key => $count) {
            if (! array_key_exists($key, $employmentLabels)) {
                $employmentRows[] = ['label' => str_replace('_', ' ', ucfirst((string) $key)), 'count' => $count];
            }
        }

        $statusRows = [];
        foreach ($statusLabels as $key => [$label, $tone]) {
            if (($statuses[$key] ?? 0) > 0) {
                $statusRows[] = ['label' => $label, 'count' => $statuses[$key], 'tone' => $tone];
            }
        }
        foreach ($statuses as $key => $count) {
            if (! array_key_exists($key, $statusLabels)) {
                $statusRows[] = ['label' => ucfirst((string) $key), 'count' => $count, 'tone' => 'gray'];
            }
        }

        return [
            'employment' => $employmentRows,
            'status' => $statusRows,
            'joiners_30d' => $joiners,
            'total' => $employees->count(),
        ];
    }

    /**
     * Leave analytics: 6-month request volumes by status, approved days
     * by leave type this year, and the open approval queue.
     *
     * @return array{months: list<array{month: string, label: string, approved: int, pending: int, rejected: int}>, types: list<array{name: string, days: int}>, pending: int, approved_days_month: int}
     */
    private function leaveStats(int $organizationId): array
    {
        $monthStart = Carbon::now()->startOfMonth();
        $requests = LeaveRequest::withoutGlobalScopes()
            ->where('organization_id', $organizationId)
            ->whereDate('start_date', '>=', $monthStart->copy()->subMonths(5)->toDateString())
            ->get(['start_date', 'status', 'days', 'leave_type_id']);

        $months = [];
        $cursor = $monthStart->copy()->subMonths(5);
        for ($i = 0; $i < 6; $i++) {
            $months[$cursor->format('Y-m')] = [
                'month' => $cursor->format('Y-m'),
                'label' => $cursor->format('M y'),
                'approved' => 0,
                'pending' => 0,
                'rejected' => 0,
            ];
            $cursor->addMonthNoOverflow();
        }

        $currentMonthStart = $monthStart->toDateString();
        $currentMonthEnd = Carbon::now()->endOfMonth()->toDateString();
        $approvedDaysMonth = 0;

        foreach ($requests as $request) {
            $start = Carbon::parse($request->start_date);
            $bucket = match ((string) $request->status) {
                LeaveRequest::APPROVED => 'approved',
                LeaveRequest::PENDING => 'pending',
                LeaveRequest::REJECTED => 'rejected',
                default => null,
            };

            $key = $start->format('Y-m');
            if ($bucket !== null && isset($months[$key])) {
                $months[$key][$bucket]++;
            }

            if ($bucket === 'approved' && $start->toDateString() >= $currentMonthStart && $start->toDateString() <= $currentMonthEnd) {
                $approvedDaysMonth += (int) $request->days;
            }
        }

        $typeNames = LeaveType::withoutGlobalScopes()
            ->where('organization_id', $organizationId)
            ->pluck('name', 'id');

        $daysByType = [];
        LeaveRequest::withoutGlobalScopes()
            ->where('organization_id', $organizationId)
            ->where('status', LeaveRequest::APPROVED)
            ->whereBetween('start_date', [
                Carbon::now()->startOfYear()->toDateString(),
                Carbon::now()->endOfYear()->toDateString(),
            ])
            ->get(['days', 'leave_type_id'])
            ->each(function (LeaveRequest $request) use (&$daysByType, $typeNames) {
                $id = (int) $request->leave_type_id;
                $name = (string) ($typeNames[$id] ?? 'Other');
                $daysByType[$name] = ($daysByType[$name] ?? 0) + (int) $request->days;
            });

        $types = [];
        foreach ($daysByType as $name => $days) {
            $types[] = ['name' => $name, 'days' => $days];
        }
        usort($types, fn (array $a, array $b) => $b['days'] <=> $a['days']);

        return [
            'months' => array_values($months),
            'types' => $types,
            'pending' => LeaveRequest::withoutGlobalScopes()
                ->where('organization_id', $organizationId)
                ->where('status', LeaveRequest::PENDING)
                ->count(),
            'approved_days_month' => $approvedDaysMonth,
        ];
    }

    /**
     * Current-month daily attendance mix by status (row counts).
     *
     * @return list<array{label: string, count: int, color: string}>
     */
    private function attendanceStatusDistribution(int $organizationId): array
    {
        $map = [
            'PRESENT' => ['Present', 'bg-emerald-500'],
            'LATE' => ['Late', 'bg-amber-400'],
            'REMOTE' => ['Remote', 'bg-sky-500'],
            'HALF_DAY' => ['Half day', 'bg-indigo-400'],
            'LEAVE' => ['On leave', 'bg-purple-500'],
            'ABSENT' => ['Absent', 'bg-red-400'],
            'HOLIDAY' => ['Holiday', 'bg-gray-400'],
            'WEEKEND' => ['Weekend', 'bg-gray-300'],
        ];

        $counts = [];
        DailyAttendance::withoutGlobalScopes()
            ->where('organization_id', $organizationId)
            ->whereDate('date', '>=', Carbon::now()->startOfMonth()->toDateString())
            ->whereDate('date', '<=', Carbon::now()->endOfMonth()->toDateString())
            ->get(['status'])
            ->each(function (DailyAttendance $row) use (&$counts) {
                $status = (string) $row->status;
                $counts[$status] = ($counts[$status] ?? 0) + 1;
            });

        $result = [];
        foreach ($map as $status => [$label, $color]) {
            if (($counts[$status] ?? 0) > 0) {
                $result[] = ['label' => $label, 'count' => $counts[$status], 'color' => $color];
            }
        }
        foreach ($counts as $status => $count) {
            if (! isset($map[$status])) {
                $result[] = [
                    'label' => str_replace('_', ' ', ucfirst(strtolower((string) $status))),
                    'count' => $count,
                    'color' => 'bg-gray-400',
                ];
            }
        }

        return $result;
    }

    /**
     * Worked vs scheduled minutes for the current month over elapsed
     * scheduled days (same ledger as the department stats).
     *
     * @param  array<string, true>  $ledger
     * @return array{worked_minutes: int, expected_minutes: int, rate: float}
     */
    private function workloadStats(int $organizationId, array $ledger): array
    {
        $today = Carbon::now()->toDateString();
        $expected = 0;

        foreach (array_keys($ledger) as $date) {
            if ($date > $today) {
                continue;
            }
            $expected += $this->scheduleExpectedMinutes(WorkSchedule::effectiveFor($organizationId, $date));
        }

        $worked = (int) DailyAttendance::withoutGlobalScopes()
            ->where('organization_id', $organizationId)
            ->whereDate('date', '>=', Carbon::now()->startOfMonth()->toDateString())
            ->whereDate('date', '<=', Carbon::now()->endOfMonth()->toDateString())
            ->sum('total_work_minutes');

        return [
            'worked_minutes' => $worked,
            'expected_minutes' => $expected,
            'rate' => $expected > 0 ? round(min(100.0, $worked / $expected * 100), 1) : 0.0,
        ];
    }

    /**
     * Attendance-score bands for the latest finalized month (empty until
     * the first monthly status run).
     *
     * @return array{label: ?string, avg: ?float, total: int, bands: list<array{label: string, count: int, color: string}>}
     */
    private function scoreDistribution(int $organizationId): array
    {
        $latest = EmployeeMonthlyStatus::withoutGlobalScopes()
            ->where('organization_id', $organizationId)
            ->orderByDesc('year')
            ->orderByDesc('month')
            ->first(['year', 'month']);

        if ($latest === null) {
            return ['label' => null, 'avg' => null, 'total' => 0, 'bands' => []];
        }

        $bands = [
            ['label' => 'Excellent (90+)', 'count' => 0, 'color' => 'bg-emerald-500'],
            ['label' => 'Good (75-89)', 'count' => 0, 'color' => 'bg-sky-500'],
            ['label' => 'Average (60-74)', 'count' => 0, 'color' => 'bg-amber-400'],
            ['label' => 'Needs attention (<60)', 'count' => 0, 'color' => 'bg-red-400'],
        ];

        $total = 0;
        $sum = 0.0;
        EmployeeMonthlyStatus::withoutGlobalScopes()
            ->where('organization_id', $organizationId)
            ->where('year', $latest->year)
            ->where('month', $latest->month)
            ->pluck('attendance_score')
            ->each(function ($score) use (&$bands, &$total, &$sum) {
                $value = (float) $score;
                $sum += $value;
                $total++;

                if ($value >= 90) {
                    $bands[0]['count']++;
                } elseif ($value >= 75) {
                    $bands[1]['count']++;
                } elseif ($value >= 60) {
                    $bands[2]['count']++;
                } else {
                    $bands[3]['count']++;
                }
            });

        return [
            'label' => $latest->periodLabel(),
            'avg' => $total > 0 ? round($sum / $total, 1) : null,
            'total' => $total,
            'bands' => $bands,
        ];
    }

    /**
     * Open risk events (window still current) grouped by type and level.
     *
     * @return array{total: int, by_type: list<array{label: string, count: int}>, by_level: list<array{label: string, count: int}>}
     */
    private function riskSummary(int $organizationId): array
    {
        $events = RiskEvent::withoutGlobalScopes()
            ->where('organization_id', $organizationId)
            ->whereDate('period_end', '>=', Carbon::now()->toDateString())
            ->get(['type', 'level']);

        $byType = [];
        $byLevel = [];

        foreach ($events as $event) {
            $typeLabel = $event->typeLabel();
            $byType[$typeLabel] = ($byType[$typeLabel] ?? 0) + 1;
            $level = (string) $event->level;
            $byLevel[$level] = ($byLevel[$level] ?? 0) + 1;
        }

        $typeRows = [];
        foreach ($byType as $label => $count) {
            $typeRows[] = ['label' => $label, 'count' => $count];
        }
        usort($typeRows, fn (array $a, array $b) => $b['count'] <=> $a['count']);

        $levelRows = [];
        foreach ($byLevel as $label => $count) {
            $levelRows[] = ['label' => $label, 'count' => $count];
        }
        usort($levelRows, fn (array $a, array $b) => $b['count'] <=> $a['count']);

        return ['total' => $events->count(), 'by_type' => $typeRows, 'by_level' => $levelRows];
    }
}
