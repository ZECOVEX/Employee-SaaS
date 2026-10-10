<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\EmployeeMonthlyStatus;
use App\Models\Organization;
use App\Models\OvertimeRecord;
use App\Notifications\MonthlyStatusReady;
use Carbon\Carbon;

/**
 * 17 monthly status snapshots: score + day counts + overtime totals per
 * employee-month, finalized once (month-end sweep runs the pending
 * overtime expiry first) and never rewritten afterwards — an existing
 * row is always skipped. Each newly finalized row notifies the employee.
 */
class MonthlyStatusService
{
    public function __construct(
        private readonly ScoreService $scores,
        private readonly RiskService $risk,
    ) {}

    /**
     * Generate missing snapshots for a month (YYYY-MM) across all orgs.
     * Returns how many rows were created; invalid months throw.
     */
    public function generate(string $month): int
    {
        if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) {
            throw new \InvalidArgumentException("The month must be in YYYY-MM format, got [{$month}].");
        }

        [$year, $monthNumber] = explode('-', $month);
        $year = (int) $year;
        $monthNumber = (int) $monthNumber;
        $start = sprintf('%04d-%02d-01', $year, $monthNumber);
        $end = (new Carbon($start))->endOfMonth()->toDateString();

        $created = 0;
        $orgs = [];

        Employee::withoutGlobalScopes()
            ->where('status', 'active')
            ->chunkById(100, function ($employees) use ($year, $monthNumber, $month, $start, $end, &$created, &$orgs) {
                foreach ($employees as $employee) {
                    $exists = EmployeeMonthlyStatus::withoutGlobalScopes()
                        ->where('organization_id', $employee->organization_id)
                        ->where('employee_id', $employee->id)
                        ->where('year', $year)
                        ->where('month', $monthNumber)
                        ->exists();

                    if ($exists) {
                        continue; // §17: finalized records never change.
                    }

                    $score = $this->scores->compute($employee, $month);

                    if ($score === null) {
                        continue; // nothing elapsed to snapshot yet
                    }

                    $overtime = $this->overtimeTotals($employee, $start, $end);
                    $inputs = $score['inputs'];

                    $status = EmployeeMonthlyStatus::withoutGlobalScopes()->create([
                        'organization_id' => $employee->organization_id,
                        'employee_id' => $employee->id,
                        'year' => $year,
                        'month' => $monthNumber,
                        'attendance_score' => $score['score'],
                        'attendance_rate' => $score['scores']['attendance_rate'],
                        'punctuality_score' => $score['scores']['punctuality'],
                        'working_hours_score' => $score['scores']['working_hours'],
                        'leave_score' => $score['scores']['leave_compliance'],
                        'days_present' => $inputs['present'] + $inputs['half'],
                        'days_absent' => $inputs['absent'],
                        'days_late' => $inputs['late'],
                        'days_on_leave' => $inputs['leave'],
                        'total_work_minutes' => $inputs['worked_minutes'],
                        'expected_work_minutes' => $inputs['expected_minutes'],
                        'overtime_minutes_approved' => $overtime['approved'],
                        'overtime_minutes_pending' => $overtime['pending'],
                        'overtime_minutes_rejected' => $overtime['rejected'],
                        'overtime_days' => $overtime['days'],
                        'status' => EmployeeMonthlyStatus::STATUS_FINALIZED,
                        'generated_at' => now(),
                    ]);

                    $created++;
                    $this->notifyEmployee($employee, $status, (float) $score['score'], $orgs);
                }
            });

        return $created;
    }

    /**
     * Push the finalized score + band to the employee (Phase 7). Organizations
     * are cached per run so the batch stays one query per org.
     *
     * @param  array<int, Organization|null>  $orgs
     */
    private function notifyEmployee(Employee $employee, EmployeeMonthlyStatus $status, float $score, array &$orgs): void
    {
        $user = $employee->user;

        if ($user === null) {
            return;
        }

        $organization = $orgs[$employee->organization_id] ??= Organization::withoutGlobalScopes()
            ->find($employee->organization_id);

        $band = $organization
            ? $this->risk->statusFor($organization, $score)['label']
            : 'Finalized';

        $user->notify(new MonthlyStatusReady(
            $status->periodLabel(),
            $score,
            $band,
            $status->days_present,
            $status->days_late,
        ));
    }

    /**
     * @return array{approved: int, pending: int, rejected: int, days: int}
     */
    private function overtimeTotals(Employee $employee, string $start, string $end): array
    {
        $records = OvertimeRecord::withoutGlobalScopes()
            ->where('employee_id', $employee->id)
            ->whereBetween('work_date', [$start, $end])
            ->get(['work_date', 'countable_minutes', 'status']);

        return [
            'approved' => (int) $records
                ->whereIn('status', [OvertimeRecord::STATUS_APPROVED, OvertimeRecord::STATUS_AUTO_APPROVED])
                ->sum('countable_minutes'),
            'pending' => (int) $records
                ->whereIn('status', [OvertimeRecord::STATUS_PENDING, OvertimeRecord::STATUS_EXPIRED])
                ->sum('countable_minutes'),
            'rejected' => (int) $records->where('status', OvertimeRecord::STATUS_REJECTED)->sum('countable_minutes'),
            'days' => $records->where('countable_minutes', '>', 0)->pluck('work_date')
                ->map->toDateString()->unique()->count(),
        ];
    }
}
