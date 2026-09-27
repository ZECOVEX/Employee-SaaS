<?php

namespace App\Services;

use App\Models\DailyAttendance;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class LeaveService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Ensure balance rows exist for the employee + year from leave type defaults.
     */
    public function ensureBalances(Employee $employee, ?int $year = null): void
    {
        $year ??= (int) date('Y');

        $types = LeaveType::withoutGlobalScopes()
            ->where('organization_id', $employee->organization_id)
            ->where('is_active', true)
            ->get();

        foreach ($types as $type) {
            LeaveBalance::withoutGlobalScopes()->firstOrCreate(
                [
                    'organization_id' => $employee->organization_id,
                    'employee_id' => $employee->id,
                    'leave_type_id' => $type->id,
                    'year' => $year,
                ],
                ['total_days' => $type->default_days_per_year],
            );
        }
    }

    /**
     * Validate the request against the remaining balance (called on approval).
     *
     * @throws \RuntimeException when balance is insufficient
     */
    public function approve(LeaveRequest $request, int $reviewerId, ?string $note = null): LeaveRequest
    {
        return DB::transaction(function () use ($request, $reviewerId, $note) {
            $request = LeaveRequest::withoutGlobalScopes()
                ->whereKey($request->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($request->status !== LeaveRequest::PENDING) {
                throw new \RuntimeException('Only pending requests can be reviewed.');
            }

            $this->ensureBalances($request->employee, (int) $request->start_date->year);

            $balance = LeaveBalance::withoutGlobalScopes()
                ->where('organization_id', $request->organization_id)
                ->where('employee_id', $request->employee_id)
                ->where('leave_type_id', $request->leave_type_id)
                ->where('year', (int) $request->start_date->year)
                ->lockForUpdate()
                ->firstOrFail();

            $leaveType = LeaveType::withoutGlobalScopes()->findOrFail($request->leave_type_id);

            // Unpaid / zero-quota types never consume a balance.
            if ($leaveType->default_days_per_year > 0 && $balance->used_days + $request->days > $balance->total_days) {
                throw new \RuntimeException(sprintf(
                    'Insufficient balance: %d of %d days remaining.',
                    $balance->total_days - $balance->used_days,
                    $balance->total_days,
                ));
            }

            $request->update([
                'status' => LeaveRequest::APPROVED,
                'reviewed_by' => $reviewerId,
                'reviewed_at' => now(),
                'review_note' => $note,
            ]);

            if ($leaveType->default_days_per_year > 0) {
                $balance->increment('used_days', $request->days);
            }

            $this->markAttendance($request, 'LEAVE');
            $this->audit->log('leave.approved', $request, ['status' => LeaveRequest::PENDING], $request->fresh()->only(['status', 'reviewed_by', 'review_note']));

            return $request;
        });
    }

    public function reject(LeaveRequest $request, int $reviewerId, ?string $note = null): LeaveRequest
    {
        return DB::transaction(function () use ($request, $reviewerId, $note) {
            if ($request->status !== LeaveRequest::PENDING) {
                throw new \RuntimeException('Only pending requests can be reviewed.');
            }

            $request->update([
                'status' => LeaveRequest::REJECTED,
                'reviewed_by' => $reviewerId,
                'reviewed_at' => now(),
                'review_note' => $note,
            ]);

            $this->audit->log('leave.rejected', $request, ['status' => LeaveRequest::PENDING], $request->fresh()->only(['status', 'reviewed_by', 'review_note']));

            return $request;
        });
    }

    /**
     * Stamp LEAVE status on daily rows for approved dates (no events yet).
     */
    private function markAttendance(LeaveRequest $request, string $status): void
    {
        $cursor = $request->start_date->copy();
        while ($cursor->lte($request->end_date)) {
            $existing = DailyAttendance::withoutGlobalScopes()
                ->where('organization_id', $request->organization_id)
                ->where('employee_id', $request->employee_id)
                ->where('date', $cursor->toDateString())
                ->first();

            if ($existing) {
                if ($existing->first_check_in === null) {
                    $existing->update(['status' => $status]);
                }
            } else {
                DailyAttendance::withoutGlobalScopes()->create([
                    'organization_id' => $request->organization_id,
                    'employee_id' => $request->employee_id,
                    'date' => $cursor->toDateString(),
                    'status' => $status,
                ]);
            }

            $cursor->addDay();
        }
    }

    /**
     * Cancel own pending request (employee action).
     */
    public function cancel(LeaveRequest $request): LeaveRequest
    {
        if ($request->status !== LeaveRequest::PENDING) {
            throw new \RuntimeException('Only pending requests can be cancelled.');
        }

        $request->update(['status' => LeaveRequest::CANCELLED]);
        $this->audit->log('leave.cancelled', $request, ['status' => LeaveRequest::PENDING], ['status' => LeaveRequest::CANCELLED]);

        return $request;
    }

    /**
     * Working days between two dates (weekdays only, inclusive).
     */
    public function workingDays(Carbon $start, Carbon $end): int
    {
        $days = 0;
        $cursor = $start->copy();
        while ($cursor->lte($end)) {
            if ($cursor->isWeekday()) {
                $days++;
            }
            $cursor->addDay();
        }

        return $days;
    }
}
