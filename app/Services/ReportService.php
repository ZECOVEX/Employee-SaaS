<?php

namespace App\Services;

use App\Models\DailyAttendance;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\Payslip;
use App\Models\WorkSchedule;
use Carbon\Carbon;

/**
 * Aggregations behind the §41 reports and the analytics dashboard.
 * Money/score columns stay out of scope where the spec defers them
 * (overtime pay [OT], scoring §15/16).
 */
class ReportService
{
    /** @var array<int, array<string, array<string, true>>> request-level ledger cache */
    private array $ledgerCache = [];

    /**
     * Scheduled workdays of a month (weekday ∧ non-holiday): date => true.
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
     * One employee's monthly report row (§41 Monthly Report). Absent covers
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
     * Analytics payload for the dashboard: attendance trend, department
     * comparison, weekday late pattern and payroll progress.
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
            'active_employees' => $activeEmployees,
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
     * rows count as zero attended days — they are part of the denominator).
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
     * Late arrivals per weekday (1 = Monday … 7 = Sunday) for the current month.
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
}
