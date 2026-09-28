<?php

namespace App\Services;

use App\Models\DailyAttendance;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\LeaveRequest;
use App\Models\SalaryRecord;
use App\Models\WorkSchedule;
use Carbon\Carbon;

/**
 * Progressive monthly salary estimate + daily salary-impact breakdown (§28).
 *
 * Inputs: the employee's effective-dated salary record, the effective work
 * schedule per day, daily attendance rows and approved leave. All money
 * outputs follow the organization's configurable deduction rules
 * (grace, method, rounding, absence/half-day treatment, unpaid leave, cap).
 */
class SalaryCalculator
{
    /**
     * @return array<string, mixed>|null null when no salary record covers the period
     */
    public function estimate(Employee $employee, string $month): ?array
    {
        if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) {
            return null;
        }

        $org = $employee->organization;
        if (! $org) {
            return null;
        }

        $tz = $org->timezone ?? 'UTC';
        $rules = $org->salaryRules();

        $periodStart = Carbon::createFromFormat('!Y-m-d', $month.'-01', $tz);
        $periodEnd = $periodStart->copy()->endOfMonth();
        $start = $periodStart->toDateString();
        $end = $periodEnd->toDateString();
        $today = Carbon::now($tz)->toDateString();
        $cutoff = $today < $end ? $today : $end;

        $record = SalaryRecord::effectiveFor($org->id, $employee->id, $start)
            ?? SalaryRecord::effectiveFor($org->id, $employee->id, $cutoff);

        if (! $record) {
            return null;
        }

        $monthlyBase = $record->basic_salary + $record->allowances;
        $schedule = WorkSchedule::effectiveFor($org->id, $start);
        $expectedMinutes = $this->expectedMinutes($schedule);
        $expectedHours = $expectedMinutes / 60;

        $dailyRate = 0.0;
        $hourlyRate = 0.0;

        $rows = DailyAttendance::withoutGlobalScopes()
            ->where('employee_id', $employee->id)
            ->whereDate('date', '>=', $start)
            ->whereDate('date', '<=', $end)
            ->get()
            ->keyBy(fn (DailyAttendance $row) => $row->date->toDateString());

        $leave = $this->leaveCoverage($employee, $start, $end);
        $holidays = $this->holidaySet($org->id, $start, $end);

        // Pass 1 — classify every calendar day of the period.
        $entries = [];
        $scheduledDays = 0;
        $cursor = $periodStart->copy();

        while ($cursor->lte($periodEnd)) {
            $date = $cursor->toDateString();
            $daySchedule = $date === $start ? $schedule : WorkSchedule::effectiveFor($org->id, $date);
            $isWorkDay = in_array((int) $cursor->dayOfWeekIso, $daySchedule?->workDayNumbers() ?? [1, 2, 3, 4, 5], true);
            $isScheduled = $isWorkDay && ! isset($holidays[$date]);
            $row = $rows->get($date);

            if ($isScheduled) {
                $scheduledDays++;
            }

            $entries[] = [
                'date' => $date,
                'scheduled' => $isScheduled,
                'future' => $date > $today,
                'row' => $row,
                'leave' => $leave[$date] ?? null,
                'holiday' => isset($holidays[$date]),
                'worked' => $row?->total_work_minutes ?? 0,
                'late' => $row?->late_minutes ?? 0,
                'overtime' => $row?->overtime_minutes ?? 0,
            ];

            $cursor->addDay();
        }

        if ($scheduledDays > 0) {
            $dailyRate = $monthlyBase / $scheduledDays;
            $hourlyRate = $expectedHours > 0 ? $dailyRate / $expectedHours : 0.0;
        }

        // Pass 2 — per-day deductions with the monthly cap applied in date order.
        $absenceMultipliers = ['full_day' => 1.0, 'half_day' => 0.5, 'none' => 0.0];
        $halfDayMultipliers = ['full_day' => 1.0, 'half_day' => 0.5, 'none' => 0.0];
        $capLimit = $monthlyBase * (float) $rules['max_deduction_percent'] / 100;
        $runningDeductions = 0.0;
        $capped = false;

        $totals = [
            'worked' => 0, 'late' => 0, 'overtime' => 0,
            'present' => 0, 'late_days' => 0, 'half' => 0, 'absent' => 0,
            'leave' => 0, 'unpaid_leave' => 0,
            'late_deduction' => 0.0, 'absence_deduction' => 0.0, 'unpaid_leave_deduction' => 0.0,
        ];
        $completedDays = 0;
        $remainingDays = 0;
        $breakdown = [];

        foreach ($entries as $entry) {
            $row = $entry['row'];
            $attended = $row !== null
                && in_array($row->status, ['PRESENT', 'LATE', 'HALF_DAY', 'REMOTE'], true);

            $status = null;
            $latePart = 0.0;
            $absencePart = 0.0;
            $unpaidPart = 0.0;

            if ($entry['scheduled'] && $entry['future']) {
                $remainingDays++;
                $status = 'FUTURE';
            } elseif ($attended) {
                $status = $row->status;
                $isAttendedScheduled = $entry['scheduled'];

                if ($isAttendedScheduled) {
                    $completedDays++;

                    if (in_array($row->status, ['PRESENT', 'LATE', 'REMOTE'], true)) {
                        $totals['present']++;
                    }
                    if ($row->status === 'LATE') {
                        $totals['late_days']++;
                    }
                }

                if ($row->status === 'HALF_DAY') {
                    $totals['half']++;

                    if ($isAttendedScheduled) {
                        $absencePart = $dailyRate * ($halfDayMultipliers[$rules['half_day_deduction']] ?? 0.5);
                    }
                }

                if ($rules['late_deduction'] === 'per_minute'
                    && $row->late_minutes > 0
                    && $isAttendedScheduled) {
                    $applicable = max(0, $row->late_minutes - (int) $rules['deduction_grace_minutes']);
                    $latePart = ($applicable / 60) * $hourlyRate;
                }
            } elseif ($entry['scheduled'] && ! $entry['future'] && $entry['leave'] !== null) {
                $completedDays++;

                if ($entry['leave']) {
                    $totals['leave']++;
                    $status = 'LEAVE';
                } else {
                    $totals['unpaid_leave']++;
                    $status = 'UNPAID LEAVE';

                    if ($rules['unpaid_leave_deduction']) {
                        $unpaidPart = $dailyRate;
                    }
                }
            } elseif ($entry['scheduled'] && ! $entry['future']) {
                $completedDays++;
                $totals['absent']++;
                $status = 'ABSENT';
                $absencePart = $dailyRate * ($absenceMultipliers[$rules['absence_deduction']] ?? 1.0);
            } else {
                $status = $row?->status ?? ($entry['holiday'] ? 'HOLIDAY' : 'WEEKEND');
            }

            $totals['worked'] += $entry['worked'];
            $totals['late'] += $entry['late'];
            $totals['overtime'] += $entry['overtime'];

            // Monthly cap on attendance deductions, applied oldest-day-first so
            // the breakdown rows always sum to the reported totals.
            $raw = $latePart + $absencePart + $unpaidPart;
            $allowed = $raw;

            if ($runningDeductions + $raw > $capLimit + 1.0E-9) {
                $allowed = max(0.0, $capLimit - $runningDeductions);
                $capped = $capped || $allowed < $raw - 1.0E-9;
            }

            $factor = $raw > 0 ? $allowed / $raw : 0.0;
            $latePart = $this->money($latePart * $factor, $rules['deduction_rounding']);
            $absencePart = $this->money($absencePart * $factor, $rules['deduction_rounding']);
            $unpaidPart = $this->money($unpaidPart * $factor, $rules['deduction_rounding']);
            $runningDeductions += $allowed;

            $totals['late_deduction'] += $latePart;
            $totals['absence_deduction'] += $absencePart;
            $totals['unpaid_leave_deduction'] += $unpaidPart;

            $breakdown[] = [
                'date' => $entry['date'],
                'status' => $status,
                'worked_minutes' => $entry['worked'],
                'late_minutes' => $entry['late'],
                'overtime_minutes' => $entry['overtime'],
                'deduction' => round($latePart + $absencePart + $unpaidPart, 2),
            ];
        }

        $lateDeduction = round($totals['late_deduction'], 2);
        $absenceDeduction = round($totals['absence_deduction'], 2);
        $unpaidDeduction = round($totals['unpaid_leave_deduction'], 2);
        $attendanceTotal = round($lateDeduction + $absenceDeduction + $unpaidDeduction, 2);

        return [
            'month' => $month,
            'currency' => $rules['currency'],
            'rules' => $rules,
            'record' => $record,
            'monthly_base' => round($monthlyBase, 2),
            'basic_salary' => $record->basic_salary,
            'allowances' => $record->allowances,
            'bonus' => $record->bonus,
            'record_deductions' => $record->deductions,
            'scheduled_days' => $scheduledDays,
            'completed_days' => $completedDays,
            'remaining_days' => $remainingDays,
            'daily_rate' => round($dailyRate, 2),
            'hourly_rate' => round($hourlyRate, 2),
            'expected_hours_per_day' => round($expectedHours, 2),
            'worked_minutes' => $totals['worked'],
            'late_minutes' => $totals['late'],
            'overtime_minutes' => $totals['overtime'],
            'present_days' => $totals['present'],
            'late_days' => $totals['late_days'],
            'half_days' => $totals['half'],
            'absent_days' => $totals['absent'],
            'leave_days' => $totals['leave'],
            'unpaid_leave_days' => $totals['unpaid_leave'],
            'late_deduction' => $lateDeduction,
            'absence_deduction' => $absenceDeduction,
            'unpaid_leave_deduction' => $unpaidDeduction,
            'attendance_deduction_total' => $attendanceTotal,
            'capped' => $capped,
            'estimated_earnings' => round($monthlyBase + $record->bonus, 2),
            'estimated_net' => round($monthlyBase + $record->bonus - $attendanceTotal - $record->deductions, 2),
            'breakdown' => $breakdown,
        ];
    }

    /**
     * Expected working minutes per day from the schedule (minus the break).
     */
    private function expectedMinutes(?WorkSchedule $schedule): int
    {
        if (! $schedule) {
            return 480;
        }

        $minutes = ((int) strtotime($schedule->end_time) - (int) strtotime($schedule->start_time)) / 60;

        if ($schedule->break_start && $schedule->break_end) {
            $minutes -= ((int) strtotime($schedule->break_end) - (int) strtotime($schedule->break_start)) / 60;
        }

        return max(0, (int) round($minutes));
    }

    /**
     * Approved leave dates → true when paid, false when unpaid.
     *
     * @return array<string, bool>
     */
    private function leaveCoverage(Employee $employee, string $start, string $end): array
    {
        $requests = LeaveRequest::withoutGlobalScopes()
            ->where('employee_id', $employee->id)
            ->where('status', LeaveRequest::APPROVED)
            ->whereDate('start_date', '<=', $end)
            ->whereDate('end_date', '>=', $start)
            ->with('leaveType:id,is_paid')
            ->get();

        $map = [];

        foreach ($requests as $request) {
            $from = $request->start_date->toDateString() > $start ? $request->start_date->toDateString() : $start;
            $to = $request->end_date->toDateString() < $end ? $request->end_date->toDateString() : $end;
            $paid = $request->leaveType?->is_paid ?? true;

            $cursor = Carbon::parse($from);
            while ($cursor->toDateString() <= $to) {
                $date = $cursor->toDateString();
                $map[$date] = ($map[$date] ?? true) && $paid;
                $cursor->addDay();
            }
        }

        return $map;
    }

    /**
     * @return array<string, true>
     */
    private function holidaySet(int $organizationId, string $start, string $end): array
    {
        $set = [];

        Holiday::withoutGlobalScopes()
            ->where('organization_id', $organizationId)
            ->whereDate('date', '>=', $start)
            ->whereDate('date', '<=', $end)
            ->get()
            ->each(function (Holiday $holiday) use (&$set) {
                $set[$holiday->date->toDateString()] = true;
            });

        return $set;
    }

    private function money(float $value, string $rounding): float
    {
        return match ($rounding) {
            'nearest' => (float) round($value),
            'whole' => (float) floor($value),
            default => round($value, 2),
        };
    }
}
