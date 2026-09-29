<?php

namespace App\Services;

use App\Models\DailyAttendance;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\Organization;
use App\Models\WorkSchedule;
use Carbon\Carbon;

/**
 * Builds today's live attendance board (§19): one row per active employee
 * with status, first check-in time and a running overtime counter after the
 * scheduled end of day. Times are naive org-local wall-clock values (§16) —
 * never timezone-converted.
 */
class LiveAttendanceBoard
{
    /**
     * @return array<int, array{employee_id: int, code: string, name: string, department: string|null, status: string, time: string|null, is_overtime: bool, overtime_label: string|null}>
     */
    public function rows(Organization $organization): array
    {
        $now = now($organization->timezone ?: 'UTC');
        $date = $now->toDateString();

        $schedule = WorkSchedule::effectiveFor($organization->id, $date);
        $end = $schedule?->end_time ? Carbon::parse($date.' '.$schedule->end_time) : null;
        $nowWall = Carbon::parse($now->format('Y-m-d H:i:s'));

        // Scope-independent: the organization is an explicit argument (the
        // ambient auth scope would otherwise hide rows from queued/CLI use.
        $employees = Employee::withoutGlobalScopes()
            ->with(['user:id,name', 'department:id,name'])
            ->where('organization_id', $organization->id)
            ->where('status', 'active')
            ->orderBy('id')
            ->get();

        $today = DailyAttendance::withoutGlobalScopes()
            ->where('organization_id', $organization->id)
            ->where('date', $date)
            ->get()
            ->keyBy('employee_id');

        $onLeave = LeaveRequest::withoutGlobalScopes()
            ->where('organization_id', $organization->id)
            ->where('status', LeaveRequest::APPROVED)
            ->whereDate('start_date', '<=', $date)
            ->whereDate('end_date', '>=', $date)
            ->pluck('employee_id')
            ->flip();

        $rows = [];

        foreach ($employees as $employee) {
            $record = $today->get($employee->id);
            $status = $record?->status
                ?? ($onLeave->has($employee->id) ? 'LEAVE' : 'ABSENT');

            $overtime = 0;
            if (
                $end
                && $record
                && $record->first_check_in
                && in_array($status, ['PRESENT', 'LATE', 'REMOTE', 'HALF_DAY'], true)
            ) {
                $overtime = $record->last_check_out
                    ? (int) $record->overtime_minutes
                    : ($nowWall->greaterThan($end)
                        ? (int) (($nowWall->getTimestamp() - $end->getTimestamp()) / 60)
                        : 0);
            }

            $rows[] = [
                'employee_id' => $employee->id,
                'code' => $employee->employee_code,
                'name' => $employee->user?->name ?? '—',
                'department' => $employee->department?->name,
                'status' => $status,
                'time' => $record?->first_check_in
                    ? $organization->formatTime($record->first_check_in)
                    : null,
                'is_overtime' => $overtime > 0,
                'overtime_label' => $overtime > 0
                    ? sprintf('+%02d:%02d', intdiv($overtime, 60), $overtime % 60)
                    : null,
            ];
        }

        return $rows;
    }
}
