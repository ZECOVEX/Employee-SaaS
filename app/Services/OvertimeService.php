<?php

namespace App\Services;

use App\Models\DailyAttendance;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\LeaveRequest;
use App\Models\OvertimeApproval;
use App\Models\OvertimePolicy;
use App\Models\OvertimeRecord;
use App\Models\SalaryRecord;
use App\Models\WorkSchedule;
use App\Notifications\OvertimeDecision;
use App\Notifications\OvertimeDetected;
use Carbon\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Overtime detection pipeline (§73-B..D, §73-H): mode → day type → late
 * offset → threshold → rounding → caps → approval status, upserted per
 * employee-day. daily_attendance.overtime_minutes mirrors the countable,
 * approved value; pending detail lives in overtime_records.
 */
class OvertimeService
{
    /** @var array<string, float|null> hourly-rate cache: "org|month|rateBase" */
    private array $rateCache = [];

    /** @var array<string, array<string, true>> holiday set cache: "org|month" */
    private array $holidayCache = [];

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * The policy version for a date; unsaved spec defaults when the org never
     * configured one (so overtime works out of the box).
     */
    public function policyFor(int $organizationId, string $date): OvertimePolicy
    {
        return OvertimePolicy::effectiveFor($organizationId, $date)
            ?? new OvertimePolicy([
                'organization_id' => $organizationId,
                ...OvertimePolicy::defaults(),
            ]);
    }

    /**
     * Recalculate the ISO week containing $date (weekly cap needs the week's
     * totals) and return the record for $date, if any.
     */
    public function recalculate(Employee $employee, string $date): ?OvertimeRecord
    {
        $tz = $employee->organization?->timezone ?? 'UTC';
        $week = $this->weekDates($date, $tz);

        foreach ($week as $weekDate) {
            $this->recalculateDay($employee, $weekDate);
        }

        $this->applyWeeklyCap($employee, $week);

        return $this->recordFor($employee, $date);
    }

    /**
     * Recalculate an arbitrary date range (policy saves, month-end runs).
     *
     * @param  list<string>  $dates
     */
    public function recalculateDates(Employee $employee, array $dates): void
    {
        $dates = array_values(array_unique($dates));

        foreach ($dates as $date) {
            $this->recalculateDay($employee, $date);
        }

        $this->applyWeeklyCap($employee, $dates);
    }

    private function recalculateDay(Employee $employee, string $date): void
    {
        $orgId = (int) $employee->organization_id;
        $tz = $employee->organization?->timezone ?? 'UTC';
        $policy = $this->policyFor($orgId, $date);

        $daily = DailyAttendance::withoutGlobalScopes()
            ->where('organization_id', $orgId)
            ->where('employee_id', $employee->id)
            ->whereDate('date', $date)
            ->first();

        if ($daily === null || ! $employee->isOvertimeEligible($policy)) {
            $this->clearRecord($employee, $date);

            return;
        }

        $schedule = WorkSchedule::effectiveFor($orgId, $date);
        $dayType = $this->dayType($employee, $date, $schedule, $tz);
        $worked = (int) $daily->total_work_minutes;
        $expected = $this->expectedMinutes($schedule);
        $late = (int) $daily->late_minutes;

        $raw = $this->rawMinutes($policy, $daily, $schedule, $date, $dayType, $expected, $worked, $tz);
        $offset = 0;

        if ($policy->offset_late_with_overtime && $dayType === OvertimeRecord::DAY_WORKING && $late > 0 && $raw > 0) {
            $offset = min($late, $raw);
            $raw -= $offset;
        }

        $flag = null;

        if ($daily->review_flag !== null && $worked > 0) {
            // §73-C.2 / §73-L: flagged days (forgotten check-out, anomalies)
            // never produce countable overtime.
            $flag = $daily->review_flag;
        } elseif ($dayType === OvertimeRecord::DAY_LEAVE && $worked > 0) {
            $flag = 'worked_during_leave';
        } elseif ($policy->require_pre_approval && $raw > 0) {
            $flag = 'pre_approval_required';
        }

        $countable = $flag === null ? $this->countableMinutes($policy, $raw) : 0;

        $status = match (true) {
            $flag !== null => OvertimeRecord::STATUS_FLAGGED,
            $countable <= 0 => OvertimeRecord::STATUS_NONE,
            $policy->approval_mode === OvertimePolicy::APPROVE_AUTO => OvertimeRecord::STATUS_AUTO_APPROVED,
            default => OvertimeRecord::STATUS_PENDING,
        };

        $existing = $this->recordFor($employee, $date);
        $previousStatus = $existing?->status;

        if ($existing !== null && $existing->isLocked()
            && $existing->worked_minutes === $worked && $existing->expected_minutes === $expected) {
            // §73-L: an approved/rejected decision stands until the underlying
            // day actually changes; re-derivation must not revert it.
            $this->mirrorDate($existing);

            return;
        }

        // Locked decisions stay unless the underlying day actually changed
        // (§73-L): a correction re-opens them for review.
        if ($existing !== null && $existing->isLocked()
            && ($existing->worked_minutes !== $worked || $existing->expected_minutes !== $expected)) {
            $old = $existing->only(['status', 'countable_minutes', 'flag_reason']);
            $status = $countable > 0
                ? ($policy->approval_mode === OvertimePolicy::APPROVE_AUTO
                    ? OvertimeRecord::STATUS_AUTO_APPROVED
                    : OvertimeRecord::STATUS_PENDING)
                : ($flag !== null ? OvertimeRecord::STATUS_FLAGGED : OvertimeRecord::STATUS_NONE);

            if ($old['status'] !== $status || $countable !== $existing->countable_minutes) {
                $this->audit->log('overtime.recalculated', $existing, $old, [
                    'status' => $status,
                    'countable_minutes' => $countable,
                    'reason' => 'attendance changed',
                ]);
            }
        }

        $multiplier = $this->multiplierFor($policy, $dayType, $daily, $date, $countable, $tz);
        $hourly = $this->hourlyRate($employee, $date, $policy->rate_base);
        $pay = $policy->compensation_type === OvertimePolicy::COMP_PAID && $hourly !== null
            ? round(($countable / 60) * $hourly * $multiplier, 2)
            : 0.0;

        if ($raw + $offset <= 0 && $flag === null && $existing === null) {
            // Nothing to record for this day (absent, no extra time, …).
            $this->mirror($daily, 0);

            return;
        }

        $attributes = [
            'organization_id' => $orgId,
            'employee_id' => $employee->id,
            'work_date' => $date,
            'day_type' => $dayType,
            'policy_id' => $policy->exists ? $policy->id : null,
            'worked_minutes' => $worked,
            'expected_minutes' => $expected,
            'raw_minutes' => $raw + $offset,
            'late_offset_minutes' => $offset,
            'countable_minutes' => $countable,
            'multiplier' => $multiplier,
            'hourly_rate_snapshot' => $hourly,
            'estimated_pay' => $pay,
            'status' => $status,
            'flag_reason' => $flag,
            'calculated_at' => now(),
        ];

        if ($existing !== null) {
            $existing->fill($attributes);
            $existing->save();
        } else {
            $existing = OvertimeRecord::withoutGlobalScopes()->create($attributes);
        }

        $approved = in_array($existing->status, [
            OvertimeRecord::STATUS_APPROVED,
            OvertimeRecord::STATUS_AUTO_APPROVED,
        ], true);

        $this->mirror($daily, $approved ? $existing->countable_minutes : 0);

        $this->notifyDetection($existing, $previousStatus, $employee);
    }

    /**
     * §73-E: approve a pending record. Approval is locked afterwards —
     * corrections only re-open it through attendance re-derivation.
     */
    public function approve(OvertimeRecord $record, int $actorUserId, ?string $reason = null): OvertimeRecord
    {
        if ($record->status !== OvertimeRecord::STATUS_PENDING) {
            throw ValidationException::withMessages([
                'record' => 'Only pending overtime can be approved.',
            ]);
        }

        $record->status = OvertimeRecord::STATUS_APPROVED;
        $record->calculated_at = now();
        $record->save();

        $minutes = (int) $record->countable_minutes;
        $this->logApproval($record, OvertimeApproval::APPROVED, $actorUserId, $minutes, $minutes, $reason);
        $this->mirrorDate($record);
        $this->audit->log('overtime.approved', $record, ['status' => OvertimeRecord::STATUS_PENDING], [
            'status' => OvertimeRecord::STATUS_APPROVED,
            'countable_minutes' => $minutes,
        ]);
        $record->employee?->user?->notify(OvertimeDecision::forRecord($record, OvertimeApproval::APPROVED, $reason));

        return $record;
    }

    /**
     * §73-E: reject requires a reason; the day stops counting (already 0 for
     * pending, kept explicit so the mirror can never disagree).
     */
    public function reject(OvertimeRecord $record, int $actorUserId, string $reason): OvertimeRecord
    {
        if ($record->status !== OvertimeRecord::STATUS_PENDING) {
            throw ValidationException::withMessages([
                'record' => 'Only pending overtime can be rejected.',
            ]);
        }

        $before = (int) $record->countable_minutes;
        $record->status = OvertimeRecord::STATUS_REJECTED;
        $record->calculated_at = now();
        $record->save();

        $this->logApproval($record, OvertimeApproval::REJECTED, $actorUserId, $before, 0, $reason);
        $this->mirrorDate($record);
        $this->audit->log('overtime.rejected', $record, ['status' => OvertimeRecord::STATUS_PENDING], [
            'status' => OvertimeRecord::STATUS_REJECTED,
            'reason' => $reason,
        ]);
        $record->employee?->user?->notify(OvertimeDecision::forRecord($record, OvertimeApproval::REJECTED, $reason));

        return $record;
    }

    /**
     * §73-E "adjust-audited": manually set the countable minutes. A decision
     * with minutes locks the record (APPROVED) so the next re-derivation can
     * never silently revert the manual change; setting 0 clears it to NONE.
     * Attendance corrections still re-open it when worked/expected change.
     */
    public function adjust(OvertimeRecord $record, int $minutes, int $actorUserId, ?string $reason = null): OvertimeRecord
    {
        if ($minutes < 0 || $minutes > 1440) {
            throw ValidationException::withMessages([
                'minutes' => 'Minutes must be between 0 and 1440.',
            ]);
        }

        $before = (int) $record->countable_minutes;

        $record->countable_minutes = $minutes;

        if ($minutes === 0) {
            $record->status = OvertimeRecord::STATUS_NONE;
            $record->flag_reason = null;
        } else {
            $record->status = OvertimeRecord::STATUS_APPROVED;
        }

        $record->estimated_pay = $this->payFor(
            $this->policyFor((int) $record->organization_id, $record->work_date->toDateString()),
            $record,
        );
        $record->calculated_at = now();
        $record->save();

        $this->logApproval($record, OvertimeApproval::ADJUSTED, $actorUserId, $before, $minutes, $reason);
        $this->mirrorDate($record);
        $this->audit->log('overtime.adjusted', $record, ['countable_minutes' => $before], [
            'countable_minutes' => $minutes,
            'status' => $record->status,
            'reason' => $reason,
        ]);

        return $record;
    }

    /**
     * One trail row per decision (§73-E).
     */
    private function logApproval(
        OvertimeRecord $record,
        string $decision,
        int $actorUserId,
        int $minutesBefore,
        int $minutesAfter,
        ?string $reason,
    ): void {
        OvertimeApproval::withoutGlobalScopes()->create([
            'organization_id' => $record->organization_id,
            'overtime_record_id' => $record->id,
            'approver_user_id' => $actorUserId,
            'decision' => $decision,
            'minutes_before' => $minutesBefore,
            'minutes_after' => $minutesAfter,
            'reason' => $reason,
            'decided_at' => now(),
        ]);
    }

    /**
     * §73-C.8: weekly cap, applied latest-day-first so older decisions stay.
     *
     * @param  list<string>  $dates
     */
    private function applyWeeklyCap(Employee $employee, array $dates): void
    {
        if ($dates === []) {
            return;
        }

        $policy = $this->policyFor((int) $employee->organization_id, min($dates));
        $cap = (int) $policy->weekly_cap_minutes;

        if ($cap <= 0) {
            return;
        }

        $records = OvertimeRecord::withoutGlobalScopes()
            ->where('employee_id', $employee->id)
            ->whereIn('work_date', $dates)
            ->where('status', '!=', OvertimeRecord::STATUS_NONE)
            ->orderByDesc('work_date')
            ->get();

        $total = (int) $records->sum('countable_minutes');

        if ($total <= $cap) {
            return;
        }

        $excess = $total - $cap;

        foreach ($records as $record) {
            if ($excess <= 0) {
                break;
            }

            if ($record->isLocked() || $record->status === OvertimeRecord::STATUS_FLAGGED) {
                continue;
            }

            $reduce = min($excess, (int) $record->countable_minutes);
            $record->countable_minutes -= $reduce;
            $excess -= $reduce;

            if ($record->countable_minutes <= 0) {
                $record->countable_minutes = 0;
                $record->status = OvertimeRecord::STATUS_NONE;
                $record->flag_reason = null;
            }

            $record->estimated_pay = $this->payFor($policy, $record);
            $record->calculated_at = now();
            $record->save();

            $this->mirrorDate($record);
        }
    }

    /**
     * §73-B raw overtime for one day, before threshold/rounding/caps.
     */
    private function rawMinutes(
        OvertimePolicy $policy,
        DailyAttendance $daily,
        ?WorkSchedule $schedule,
        string $date,
        string $dayType,
        int $expected,
        int $worked,
        string $tz,
    ): int {
        if ($worked <= 0) {
            return 0;
        }

        if ($dayType === OvertimeRecord::DAY_LEAVE) {
            return $worked; // reviewed, never counted automatically (§73-C.4)
        }

        $onRestDay = in_array($dayType, [OvertimeRecord::DAY_WEEKEND, OvertimeRecord::DAY_HOLIDAY], true);

        if ($onRestDay) {
            // §73-C.4: all worked minutes are overtime (weekend_all_overtime);
            // when off, only minutes beyond a standard shift count (mode-based:
            // AFTER_OFFICE_END looks at the check-out, ABOVE_EXPECTED_HOURS at
            // worked minus expected).
            if (! $policy->weekend_all_overtime) {
                return $policy->mode === OvertimePolicy::MODE_AFTER_END
                    ? $this->minutesAfterEnd($daily, $schedule, $date)
                    : max(0, $worked - $expected);
            }

            return $worked;
        }

        if ($policy->mode === OvertimePolicy::MODE_AFTER_END) {
            return $this->minutesAfterEnd($daily, $schedule, $date);
        }

        return max(0, $worked - $expected);
    }

    /**
     * AFTER_OFFICE_END: minutes of the last check-out beyond the shift end
     * (early arrival never creates overtime, §73-B).
     */
    private function minutesAfterEnd(DailyAttendance $daily, ?WorkSchedule $schedule, string $date): int
    {
        if (! $schedule || ! $daily->last_check_out) {
            return 0;
        }

        $end = Carbon::parse($date.' '.$schedule->end_time);
        $checkout = $daily->last_check_out->copy();

        return $checkout->greaterThan($end) ? (int) $end->diffInMinutes($checkout) : 0;
    }

    /**
     * §73-C.6..8 — start threshold, rounding, daily cap.
     */
    private function countableMinutes(OvertimePolicy $policy, int $raw): int
    {
        if ($raw <= 0) {
            return 0;
        }

        if ($raw < (int) $policy->start_threshold_minutes) {
            return 0;
        }

        $rounded = $this->roundMinutes($raw, (int) $policy->rounding_minutes, $policy->rounding_method);

        if ($rounded <= 0) {
            return 0;
        }

        $cap = (int) $policy->daily_cap_minutes;

        return $cap > 0 ? min($rounded, $cap) : $rounded;
    }

    private function roundMinutes(int $minutes, int $step, string $method): int
    {
        if ($step <= 1) {
            return $minutes;
        }

        return match ($method) {
            OvertimePolicy::ROUND_UP => (int) (ceil($minutes / $step) * $step),
            OvertimePolicy::ROUND_DOWN => (int) (floor($minutes / $step) * $step),
            default => (int) (round($minutes / $step) * $step),
        };
    }

    private function dayType(Employee $employee, string $date, ?WorkSchedule $schedule, string $tz): string
    {
        $onLeave = LeaveRequest::withoutGlobalScopes()
            ->where('employee_id', $employee->id)
            ->where('status', LeaveRequest::APPROVED)
            ->whereDate('start_date', '<=', $date)
            ->whereDate('end_date', '>=', $date)
            ->exists();

        if ($onLeave) {
            return OvertimeRecord::DAY_LEAVE;
        }

        $holiday = Holiday::withoutGlobalScopes()
            ->where('organization_id', $employee->organization_id)
            ->where('date', $date)
            ->exists();

        if ($holiday) {
            return OvertimeRecord::DAY_HOLIDAY;
        }

        $dayOfWeek = (int) Carbon::parse($date, $tz)->dayOfWeekIso;
        $isWorkDay = in_array($dayOfWeek, $schedule?->workDayNumbers() ?? [1, 2, 3, 4, 5], true);

        return $isWorkDay ? OvertimeRecord::DAY_WORKING : OvertimeRecord::DAY_WEEKEND;
    }

    /**
     * Day-type multiplier with the optional night window blended in (§73-F).
     * The night portion is the tail of the overtime window
     * [checkout − countable, checkout] that overlaps the night window.
     */
    private function multiplierFor(
        OvertimePolicy $policy,
        string $dayType,
        DailyAttendance $daily,
        string $date,
        int $countable,
        string $tz,
    ): float {
        $base = match ($dayType) {
            OvertimeRecord::DAY_HOLIDAY => (float) $policy->holiday_multiplier,
            OvertimeRecord::DAY_WEEKEND => (float) $policy->weekend_multiplier,
            default => (float) $policy->weekday_multiplier,
        };

        if ($countable <= 0 || $policy->night_multiplier === null
            || $policy->night_window_start === null || $policy->night_window_end === null
            || ! $daily->last_check_out) {
            return $base;
        }

        $checkout = $daily->last_check_out->copy();
        $windowStart = $checkout->copy()->subMinutes($countable);

        $nightStart = Carbon::parse($date.' '.$policy->night_window_start);
        $nightEnd = Carbon::parse($date.' '.$policy->night_window_end);

        if ($nightEnd->lessThan($nightStart)) {
            $nightEnd->addDay();
        }

        $overlapStart = max($windowStart->timestamp, $nightStart->timestamp);
        $overlapEnd = min($checkout->timestamp, $nightEnd->timestamp);
        $nightMinutes = max(0, (int) round(($overlapEnd - $overlapStart) / 60));

        if ($nightMinutes <= 0) {
            return $base;
        }

        $nightMinutes = min($nightMinutes, $countable);

        return round(
            (($countable - $nightMinutes) * $base + $nightMinutes * (float) $policy->night_multiplier)
            / $countable,
            2,
        );
    }

    private function payFor(OvertimePolicy $policy, OvertimeRecord $record): float
    {
        if ($policy->compensation_type !== OvertimePolicy::COMP_PAID || $record->hourly_rate_snapshot === null) {
            return 0.0;
        }

        return round(($record->countable_minutes / 60) * $record->hourly_rate_snapshot * $record->multiplier, 2);
    }

    /**
     * Overtime hourly rate for the date's effective salary record (§73-F,
     * §28): base ÷ scheduled days ÷ expected hours — same derivation as the
     * salary estimate so the two never disagree.
     */
    private function hourlyRate(Employee $employee, string $date, string $rateBase): ?float
    {
        $record = SalaryRecord::effectiveFor((int) $employee->organization_id, $employee->id, $date);

        if (! $record) {
            return null;
        }

        $month = substr($date, 0, 7);
        $cacheKey = $employee->organization_id.'|'.$month.'|'.$rateBase;

        if (array_key_exists($cacheKey, $this->rateCache)) {
            return $this->rateCache[$cacheKey];
        }

        $base = match ($rateBase) {
            OvertimePolicy::RATE_BASIC => (float) $record->basic_salary,
            OvertimePolicy::RATE_CUSTOM => (float) $record->basic_salary + (float) $record->allowances,
            default => (float) $record->gross_salary,
        };

        $org = $employee->organization;
        $tz = $org?->timezone ?? 'UTC';
        $start = Carbon::createFromFormat('!Y-m-d', $month.'-01', $tz);
        $end = $start->copy()->endOfMonth();
        $holidaySet = $this->holidaysFor((int) $employee->organization_id, $start, $end);

        $scheduledDays = 0;
        $cursor = $start->copy();

        while ($cursor->lte($end)) {
            $daySchedule = WorkSchedule::effectiveFor((int) $employee->organization_id, $cursor->toDateString());
            $isWorkDay = in_array((int) $cursor->dayOfWeekIso, $daySchedule?->workDayNumbers() ?? [1, 2, 3, 4, 5], true);

            if ($isWorkDay && ! isset($holidaySet[$cursor->toDateString()])) {
                $scheduledDays++;
            }

            $cursor->addDay();
        }

        $expectedHours = $this->expectedMinutes(WorkSchedule::effectiveFor((int) $employee->organization_id, $date)) / 60;

        $rate = ($scheduledDays <= 0 || $expectedHours <= 0)
            ? null
            : round($base / $scheduledDays / $expectedHours, 4);

        return $this->rateCache[$cacheKey] = $rate;
    }

    /**
     * @return array<string, true>
     */
    private function holidaysFor(int $organizationId, Carbon $start, Carbon $end): array
    {
        $key = $organizationId.'|'.$start->format('Y-m');

        if (isset($this->holidayCache[$key])) {
            return $this->holidayCache[$key];
        }

        $set = [];

        Holiday::withoutGlobalScopes()
            ->where('organization_id', $organizationId)
            ->whereDate('date', '>=', $start->toDateString())
            ->whereDate('date', '<=', $end->toDateString())
            ->get()
            ->each(function (Holiday $holiday) use (&$set) {
                $set[$holiday->date->toDateString()] = true;
            });

        return $this->holidayCache[$key] = $set;
    }

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

    private function recordFor(Employee $employee, string $date): ?OvertimeRecord
    {
        return OvertimeRecord::withoutGlobalScopes()
            ->where('employee_id', $employee->id)
            ->whereDate('work_date', $date)
            ->first();
    }

    private function clearRecord(Employee $employee, string $date): void
    {
        $record = $this->recordFor($employee, $date);

        if ($record !== null && ! $record->isLocked()) {
            $record->delete();
        }

        DailyAttendance::withoutGlobalScopes()
            ->where('employee_id', $employee->id)
            ->whereDate('date', $date)
            ->update(['overtime_minutes' => 0]);
    }

    private function mirror(DailyAttendance $daily, int $minutes): void
    {
        if ((int) $daily->overtime_minutes === $minutes) {
            return;
        }

        $daily->overtime_minutes = $minutes;
        $daily->save();
    }

    private function mirrorDate(OvertimeRecord $record): void
    {
        $daily = DailyAttendance::withoutGlobalScopes()
            ->where('employee_id', $record->employee_id)
            ->whereDate('date', $record->work_date->toDateString())
            ->first();

        if ($daily === null) {
            return;
        }

        $approved = in_array($record->status, [
            OvertimeRecord::STATUS_APPROVED,
            OvertimeRecord::STATUS_AUTO_APPROVED,
        ], true);

        $this->mirror($daily, $approved ? $record->countable_minutes : 0);
    }

    /**
     * §73-I.7 "detected" — fires once per day when overtime first becomes
     * countable, not on every re-derivation.
     */
    private function notifyDetection(OvertimeRecord $record, ?string $previousStatus, Employee $employee): void
    {
        if (! $record->hasCountableOvertime()) {
            return;
        }

        if (in_array($previousStatus, [
            OvertimeRecord::STATUS_PENDING,
            OvertimeRecord::STATUS_AUTO_APPROVED,
            OvertimeRecord::STATUS_APPROVED,
        ], true)) {
            return;
        }

        $employee->user?->notify(OvertimeDetected::forRecord($record));
    }

    /**
     * @return list<string> ISO week (Mon–Sun) containing $date
     */
    private function weekDates(string $date, string $tz): array
    {
        $start = Carbon::parse($date, $tz)->startOfWeek(Carbon::MONDAY);
        $dates = [];

        for ($i = 0; $i < 7; $i++) {
            $dates[] = $start->copy()->addDays($i)->toDateString();
        }

        return $dates;
    }
}
