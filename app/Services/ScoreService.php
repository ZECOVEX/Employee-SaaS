<?php

namespace App\Services;

use App\Models\DailyAttendance;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\LeaveRequest;
use App\Models\OvertimeRecord;
use App\Models\WorkSchedule;
use Carbon\Carbon;

/**
 * Attendance score engine (§16): a transparent, weighted formula whose
 * breakdown is always shown — employees must understand WHY they got their
 * score, and the formula is never hidden.
 *
 *   Attendance Rate × 40%  + Punctuality × 25%
 * + Working Hours × 20%   + Leave Compliance × 15%   (+ capped OT bonus)
 *
 * Calculation rules (§15 "calculation rules", spec defaults until an admin
 * configures otherwise; weights read from org settings with spec fallbacks):
 *
 * - Attendance Rate: attended share of attendable days (elapsed scheduled
 *   days minus approved-leave days); HALF_DAY counts 0.5.
 * - Punctuality: 100 − late days as a share of attendable days.
 * - Working Hours: worked ÷ expected minutes on attendable days, capped at
 *   100 and capped per-day at the expected minutes — overtime minutes are
 *   never added, so extra hours cannot mask absences or short days (§16 [OT]).
 * - Leave Compliance: approved-leave days ÷ (approved leave + unexcused
 *   absence). Approved leave never lowers any component.
 * - Overtime bonus (§16 [OT], default none): +1 point per 4 approved
 *   overtime hours, capped at OvertimePolicy.overtime_score_bonus_max and
 *   always displayed as its own line.
 */
class ScoreService
{
    /** @var array<string, WorkSchedule|null> effective schedule cache: "org|date" */
    private array $scheduleCache = [];

    /** @var array<string, array<string, true>> holiday cache: "org|month" */
    private array $holidayCache = [];

    public function __construct(private readonly OvertimeService $overtime) {}

    /**
     * @return array<string, mixed>|null null when the month has no elapsed
     *                                   scheduled day yet (nothing to score)
     */
    public function compute(Employee $employee, string $month): ?array
    {
        if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) {
            return null;
        }

        $org = $employee->organization;
        if (! $org) {
            return null;
        }

        $tz = $org->timezone ?? 'UTC';
        $periodStart = Carbon::createFromFormat('!Y-m-d', $month.'-01', $tz);
        $periodEnd = $periodStart->copy()->endOfMonth();
        $today = Carbon::now($tz)->toDateString();

        $leave = $this->leaveCoverage($employee, $periodStart->toDateString(), $periodEnd->toDateString());

        $holidayKey = (int) $org->id.'|'.$month;
        if (! array_key_exists($holidayKey, $this->holidayCache)) {
            $this->holidayCache[$holidayKey] = $this->holidaySet((int) $org->id, $periodStart, $periodEnd);
        }
        $holidays = $this->holidayCache[$holidayKey];
        $rows = DailyAttendance::withoutGlobalScopes()
            ->where('employee_id', $employee->id)
            ->whereDate('date', '>=', $periodStart->toDateString())
            ->whereDate('date', '<=', $periodEnd->toDateString())
            ->get()
            ->keyBy(fn (DailyAttendance $row) => $row->date->toDateString());

        $elapsedScheduled = 0;
        $attended = 0;
        $half = 0;
        $late = 0;
        $leaveDays = 0;
        $absent = 0;
        $worked = 0;
        $workedCapped = 0;
        $expectedAttendable = 0;

        $cursor = $periodStart->copy();

        while ($cursor->lte($periodEnd)) {
            $date = $cursor->toDateString();
            $future = $date > $today;

            if (! $future) {
                $scheduleKey = (int) $org->id.'|'.$date;
                if (! array_key_exists($scheduleKey, $this->scheduleCache)) {
                    $this->scheduleCache[$scheduleKey] = WorkSchedule::effectiveFor((int) $org->id, $date);
                }
                $daySchedule = $this->scheduleCache[$scheduleKey];
                $isWorkDay = in_array((int) $cursor->dayOfWeekIso, $daySchedule?->workDayNumbers() ?? [1, 2, 3, 4, 5], true);

                if ($isWorkDay && ! isset($holidays[$date])) {
                    $elapsedScheduled++;
                    $row = $rows->get($date);
                    $isAttended = $row !== null
                        && in_array($row->status, ['PRESENT', 'LATE', 'HALF_DAY', 'REMOTE'], true);
                    $onLeave = ! $isAttended && isset($leave[$date]);
                    $expected = $this->expectedMinutes($daySchedule);

                    if ($isAttended) {
                        $attended++;

                        if ($row->status === 'HALF_DAY') {
                            $half++;
                        }
                        if ($row->status === 'LATE') {
                            $late++;
                        }

                        $worked += (int) $row->total_work_minutes;
                        $workedCapped += min((int) $row->total_work_minutes, $expected);
                        $expectedAttendable += $expected;
                    } elseif ($onLeave) {
                        $leaveDays++;
                    } else {
                        $absent++;
                        // Absences lower Working Hours; approved leave does not.
                        $expectedAttendable += $expected;
                    }
                }
            }

            $cursor->addDay();
        }

        if ($elapsedScheduled === 0) {
            return null;
        }

        $attendable = max(0, $elapsedScheduled - $leaveDays);

        $attendanceRate = $attendable > 0
            ? min(100.0, 100 * ($attended - 0.5 * $half) / $attendable)
            : 100.0;
        $punctuality = $attendable > 0
            ? ($attended > 0 ? max(0.0, 100 - 100 * $late / $attendable) : 0.0)
            : 100.0;
        $workingHours = $expectedAttendable > 0
            ? min(100.0, 100 * $workedCapped / $expectedAttendable)
            : 100.0;
        $exposure = $leaveDays + $absent;
        $leaveCompliance = $exposure > 0 ? 100 * $leaveDays / $exposure : 100.0;

        $weights = $this->weights($org->settings ?? []);

        $raw = [
            'attendance_rate' => round($attendanceRate, 2),
            'punctuality' => round($punctuality, 2),
            'working_hours' => round($workingHours, 2),
            'leave_compliance' => round($leaveCompliance, 2),
        ];

        $labels = [
            'attendance_rate' => 'Attendance Rate',
            'punctuality' => 'Punctuality',
            'working_hours' => 'Working Hours',
            'leave_compliance' => 'Leave Compliance',
        ];

        // Weighted from the *displayed* component values so the breakdown
        // always reconciles: shown × shown = shown (§16 "display the
        // breakdown", "never hide the formula").
        $components = [];
        $base = 0.0;

        foreach ($raw as $key => $value) {
            $weighted = round($value * $weights[$key] / 100, 2);
            $base += $weighted;

            $components[] = [
                'key' => $key,
                'label' => $labels[$key],
                'score' => $value,
                'weight' => $weights[$key],
                'weighted' => $weighted,
            ];
        }

        $base = round($base, 2);

        $policy = $this->overtime->policyFor((int) $org->id, $month.'-01');
        $bonusMax = (float) $policy->overtime_score_bonus_max;
        $approvedOvertime = (int) OvertimeRecord::withoutGlobalScopes()
            ->where('employee_id', $employee->id)
            ->whereDate('work_date', '>=', $periodStart->toDateString())
            ->whereDate('work_date', '<=', $periodEnd->toDateString())
            ->whereIn('status', [
                OvertimeRecord::STATUS_APPROVED,
                OvertimeRecord::STATUS_AUTO_APPROVED,
            ])
            ->sum('countable_minutes');
        $bonus = $bonusMax > 0 ? round(min($bonusMax, $approvedOvertime / 240), 2) : 0.0;

        $formula = implode(' + ', array_map(
            fn (array $component): string => sprintf(
                '%s × %s%%',
                $component['label'],
                rtrim(rtrim(number_format($component['weight'], 2, '.', ''), '0'), '.'),
            ),
            $components,
        ));

        return [
            'month' => $month,
            'components' => $components,
            'scores' => $raw,
            'weights' => $weights,
            'base_score' => $base,
            'overtime_bonus' => $bonus,
            'overtime_bonus_max' => $bonusMax,
            'approved_overtime_minutes' => $approvedOvertime,
            'score' => round($base + $bonus, 2),
            'formula' => $formula,
            'inputs' => [
                'elapsed_scheduled' => $elapsedScheduled,
                'attendable' => $attendable,
                'present' => $attended - $half,
                'half' => $half,
                'late' => $late,
                'absent' => $absent,
                'leave' => $leaveDays,
                'worked_minutes' => $worked,
                'worked_capped_minutes' => $workedCapped,
                'expected_minutes' => $expectedAttendable,
            ],
        ];
    }

    /**
     * §15: score weights are admin-configurable; the spec's 40/25/20/15 are
     * the fallback until settings exist.
     *
     * @param  array<string, mixed>  $settings
     * @return array<string, float>
     */
    private function weights(array $settings): array
    {
        $default = [
            'attendance_rate' => 40.0,
            'punctuality' => 25.0,
            'working_hours' => 20.0,
            'leave_compliance' => 15.0,
        ];

        $map = [
            'attendance_rate' => 'score_weight_attendance',
            'punctuality' => 'score_weight_punctuality',
            'working_hours' => 'score_weight_working_hours',
            'leave_compliance' => 'score_weight_leave',
        ];

        $weights = [];

        foreach ($default as $key => $fallback) {
            $value = $settings[$map[$key]] ?? $fallback;
            $weights[$key] = is_numeric($value) ? max(0.0, (float) $value) : $fallback;
        }

        return $weights;
    }

    /**
     * Approved leave dates in the range (paid and unpaid both count as
     * properly requested absence).
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
            ->get();

        $map = [];

        foreach ($requests as $request) {
            $from = $request->start_date->toDateString() > $start ? $request->start_date->toDateString() : $start;
            $to = $request->end_date->toDateString() < $end ? $request->end_date->toDateString() : $end;

            $cursor = Carbon::parse($from);
            while ($cursor->toDateString() <= $to) {
                $map[$cursor->toDateString()] = true;
                $cursor->addDay();
            }
        }

        return $map;
    }

    /**
     * @return array<string, true>
     */
    private function holidaySet(int $organizationId, Carbon $start, Carbon $end): array
    {
        $set = [];

        Holiday::withoutGlobalScopes()
            ->where('organization_id', $organizationId)
            ->whereDate('date', '>=', $start->toDateString())
            ->whereDate('date', '<=', $end->toDateString())
            ->get()
            ->each(function (Holiday $holiday) use (&$set) {
                $set[$holiday->date->toDateString()] = true;
            });

        return $set;
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
}
