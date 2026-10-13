<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\DailyAttendance;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Notifications\LeaveStatusChanged;
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
     * Approve a request. Re-reviewable: works from PENDING and REJECTED
     * (deducting the balance), and from APPROVED (idempotent — no double
     * deduction). CANCELLED requests are terminal.
     *
     * @throws \RuntimeException when balance is insufficient or the request was cancelled
     */
    public function approve(LeaveRequest $request, int $reviewerId, ?string $note = null): LeaveRequest
    {
        return DB::transaction(function () use ($request, $reviewerId, $note) {
            $request = LeaveRequest::withoutGlobalScopes()
                ->whereKey($request->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($request->status === LeaveRequest::CANCELLED) {
                throw new \RuntimeException('A cancelled request cannot be reviewed.');
            }

            $previous = $request->status;
            $wasApproved = $previous === LeaveRequest::APPROVED;
            $leaveType = LeaveType::withoutGlobalScopes()->findOrFail($request->leave_type_id);
            $start = Carbon::parse($request->start_date);
            $end = Carbon::parse($request->end_date);

            if (! $wasApproved) {
                $this->ensureBalances($request->employee, (int) $start->year);

                $balance = LeaveBalance::withoutGlobalScopes()
                    ->where('organization_id', $request->organization_id)
                    ->where('employee_id', $request->employee_id)
                    ->where('leave_type_id', $request->leave_type_id)
                    ->where('year', (int) $start->year)
                    ->lockForUpdate()
                    ->firstOrFail();

                // Unpaid / zero-quota types never consume a balance.
                if ($leaveType->default_days_per_year > 0 && $balance->used_days + $request->days > $balance->total_days) {
                    throw new \RuntimeException(sprintf(
                        'Insufficient balance: %d of %d days remaining.',
                        $balance->total_days - $balance->used_days,
                        $balance->total_days,
                    ));
                }
            }

            $request->update([
                'status' => LeaveRequest::APPROVED,
                'reviewed_by' => $reviewerId,
                'reviewed_at' => now(),
                'review_note' => $note,
            ]);

            if (! $wasApproved) {
                if ($leaveType->default_days_per_year > 0) {
                    LeaveBalance::withoutGlobalScopes()
                        ->where('organization_id', $request->organization_id)
                        ->where('employee_id', $request->employee_id)
                        ->where('leave_type_id', $request->leave_type_id)
                        ->where('year', (int) $start->year)
                        ->lockForUpdate()
                        ->firstOrFail()
                        ->increment('used_days', $request->days);
                }

                $this->markAttendance($request, 'LEAVE');
            }

            $this->audit->log('leave.approved', $request, ['status' => $previous], $request->fresh()->only(['status', 'reviewed_by', 'review_note']));

            if ($previous !== LeaveRequest::APPROVED) {
                $request->employee?->user?->notify(new LeaveStatusChanged(
                    'approved',
                    $leaveType->code,
                    $start->format('d M Y'),
                    $end->format('d M Y'),
                    (int) $request->days,
                    $note,
                ));
            }

            return $request;
        });
    }

    /**
     * Reject a request. Re-reviewable: works from PENDING and APPROVED
     * (refunding the balance and clearing LEAVE attendance stamps when the
     * request had been approved), and from REJECTED (idempotent). CANCELLED
     * requests are terminal.
     */
    public function reject(LeaveRequest $request, int $reviewerId, ?string $note = null): LeaveRequest
    {
        return DB::transaction(function () use ($request, $reviewerId, $note) {
            $request = LeaveRequest::withoutGlobalScopes()
                ->whereKey($request->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($request->status === LeaveRequest::CANCELLED) {
                throw new \RuntimeException('A cancelled request cannot be reviewed.');
            }

            $previous = $request->status;

            if ($previous === LeaveRequest::APPROVED) {
                $this->refund($request);
                $this->unmarkAttendance($request);
            }

            $request->update([
                'status' => LeaveRequest::REJECTED,
                'reviewed_by' => $reviewerId,
                'reviewed_at' => now(),
                'review_note' => $note,
            ]);

            $this->audit->log('leave.rejected', $request, ['status' => $previous], $request->fresh()->only(['status', 'reviewed_by', 'review_note']));

            if ($previous !== LeaveRequest::REJECTED) {
                $leaveType = LeaveType::withoutGlobalScopes()->findOrFail($request->leave_type_id);
                $request->employee?->user?->notify(new LeaveStatusChanged(
                    'rejected',
                    $leaveType->code,
                    Carbon::parse($request->start_date)->format('d M Y'),
                    Carbon::parse($request->end_date)->format('d M Y'),
                    (int) $request->days,
                    $note,
                ));
            }

            return $request;
        });
    }

    /**
     * Edit a request's type, dates or reason. Allowed on any non-cancelled
     * status — including APPROVED ("reduce anytime"): the old deduction is
     * refunded, the new range re-checked against the balance, and the LEAVE
     * attendance stamps are moved to the new dates. PENDING/REJECTED edits
     * never touch the balance (the deduction happens at approval).
     *
     * @param  array{leave_type_id: int, start_date: string, end_date: string, days: int, reason: ?string}  $data
     *
     * @throws \RuntimeException when the request was cancelled, the range holds
     *                           no working days, or the new range no longer
     *                           fits the balance (the whole edit rolls back)
     */
    public function edit(LeaveRequest $request, array $data): LeaveRequest
    {
        return DB::transaction(function () use ($request, $data) {
            $request = LeaveRequest::withoutGlobalScopes()
                ->whereKey($request->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($request->status === LeaveRequest::CANCELLED) {
                throw new \RuntimeException('A cancelled request cannot be edited.');
            }

            if ($data['days'] < 1) {
                throw new \RuntimeException('Range contains no working days.');
            }

            $wasApproved = $request->status === LeaveRequest::APPROVED;
            $old = [
                'leave_type_id' => (int) $request->leave_type_id,
                'start_date' => Carbon::parse($request->start_date)->toDateString(),
                'end_date' => Carbon::parse($request->end_date)->toDateString(),
                'days' => (int) $request->days,
                'reason' => $request->reason,
            ];

            if ($wasApproved) {
                // Give the old deduction back to its (type, year) bucket …
                $this->refundDays(
                    $request,
                    $old['leave_type_id'],
                    (int) Carbon::parse($old['start_date'])->year,
                    $old['days'],
                );

                // … and clear the old LEAVE stamps before the range moves.
                $this->unmarkAttendance($request);

                // Consume the new range — throws (rolling everything back)
                // when it no longer fits the balance.
                $this->deductDays(
                    $request,
                    (int) $data['leave_type_id'],
                    Carbon::parse($data['start_date']),
                    (int) $data['days'],
                );
            }

            $request->update([
                'leave_type_id' => $data['leave_type_id'],
                'start_date' => $data['start_date'],
                'end_date' => $data['end_date'],
                'days' => $data['days'],
                'reason' => $data['reason'] ?? null,
            ]);

            if ($wasApproved) {
                $this->markAttendance($request, 'LEAVE');
            }

            $this->audit->log('leave.edited', $request, $old, $request->fresh()->only([
                'leave_type_id', 'start_date', 'end_date', 'days', 'reason',
            ]));

            $leaveType = LeaveType::withoutGlobalScopes()->findOrFail((int) $data['leave_type_id']);
            $request->employee?->user?->notify(new LeaveStatusChanged(
                'updated',
                $leaveType->code,
                Carbon::parse($request->start_date)->format('d M Y'),
                Carbon::parse($request->end_date)->format('d M Y'),
                (int) $request->days,
            ));

            return $request;
        });
    }

    /**
     * Return the deducted days to the balance (reject-after-approve, or an
     * approved edit shrinking the range). The write is clamped at zero —
     * used_days is an unsigned column.
     */
    private function refund(LeaveRequest $request): void
    {
        $this->refundDays(
            $request,
            (int) $request->leave_type_id,
            (int) Carbon::parse($request->start_date)->year,
            (int) $request->days,
        );
    }

    /**
     * Return $days to the (leave type, year) bucket. Zero-quota types never
     * had a balance; a missing balance row is a no-op.
     */
    private function refundDays(LeaveRequest $request, int $leaveTypeId, int $year, int $days): void
    {
        $leaveType = LeaveType::withoutGlobalScopes()->findOrFail($leaveTypeId);

        if ($leaveType->default_days_per_year <= 0) {
            return;
        }

        $balance = LeaveBalance::withoutGlobalScopes()
            ->where('organization_id', $request->organization_id)
            ->where('employee_id', $request->employee_id)
            ->where('leave_type_id', $leaveTypeId)
            ->where('year', $year)
            ->lockForUpdate()
            ->first();

        if ($balance === null) {
            return;
        }

        $balance->update(['used_days' => max(0, $balance->used_days - $days)]);
    }

    /**
     * Consume quota for an approved range, re-checking the balance inside
     * the open transaction. Zero-quota (unpaid) types never consume a balance.
     *
     * @throws \RuntimeException when the remaining balance cannot cover $days
     */
    private function deductDays(LeaveRequest $request, int $leaveTypeId, Carbon $start, int $days): void
    {
        $leaveType = LeaveType::withoutGlobalScopes()->findOrFail($leaveTypeId);

        if ($leaveType->default_days_per_year <= 0) {
            return;
        }

        $this->ensureBalances($request->employee, (int) $start->year);

        $balance = LeaveBalance::withoutGlobalScopes()
            ->where('organization_id', $request->organization_id)
            ->where('employee_id', $request->employee_id)
            ->where('leave_type_id', $leaveTypeId)
            ->where('year', (int) $start->year)
            ->lockForUpdate()
            ->firstOrFail();

        if ($balance->used_days + $days > $balance->total_days) {
            throw new \RuntimeException(sprintf(
                'Insufficient balance: %d of %d days remaining.',
                $balance->total_days - $balance->used_days,
                $balance->total_days,
            ));
        }

        $balance->increment('used_days', $days);
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
     * Reverse markAttendance on reject-after-approve: delete LEAVE rows that
     * carry no check-in (rows with real punches were never stamped by leave).
     */
    private function unmarkAttendance(LeaveRequest $request): void
    {
        DailyAttendance::withoutGlobalScopes()
            ->where('organization_id', $request->organization_id)
            ->where('employee_id', $request->employee_id)
            ->whereBetween('date', [
                Carbon::parse($request->start_date)->toDateString(),
                Carbon::parse($request->end_date)->toDateString(),
            ])
            ->where('status', 'LEAVE')
            ->whereNull('first_check_in')
            ->delete();
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
