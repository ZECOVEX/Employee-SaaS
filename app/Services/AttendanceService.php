<?php

namespace App\Services;

use App\Models\AttendanceEvent;
use App\Models\AttendanceTerminal;
use App\Models\DailyAttendance;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\NfcCard;
use App\Models\WorkSchedule;
use App\Notifications\LateArrival;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class AttendanceService
{
    /** Spec §73 default max shift length (probable missed check-out guard). */
    public const MAX_SHIFT_MINUTES = 960;

    /** Spec §72-B: a day with this many segments is flagged as an anomaly. */
    public const MAX_SEGMENTS = 15;

    public function __construct(private readonly OvertimeService $overtime) {}

    /**
     * Process an NFC punch from a terminal.
     *
     * @return array{event: AttendanceEvent, daily: DailyAttendance, action: string}
     *
     * @throws \RuntimeException when the card or employee cannot be punched
     */
    public function punch(AttendanceTerminal $terminal, string $cardToken): array
    {
        return DB::transaction(function () use ($terminal, $cardToken) {
            $card = NfcCard::withoutGlobalScopes()
                ->where('organization_id', $terminal->organization_id)
                ->where('card_token', $cardToken)
                ->lockForUpdate()
                ->first();

            if (! $card || $card->status !== 'active') {
                throw new \RuntimeException('Unknown or inactive NFC card.');
            }

            $employee = Employee::withoutGlobalScopes()
                ->where('organization_id', $terminal->organization_id)
                ->where('id', $card->employee_id)
                ->lockForUpdate()
                ->first();

            if (! $employee || $employee->status !== 'active') {
                throw new \RuntimeException('Card is not linked to an active employee.');
            }

            $org = $terminal->organization;
            $now = Carbon::now($org?->timezone ?? 'UTC');
            $date = $now->toDateString();

            // Events are stored as naive wall-clock times in the org timezone,
            // so the debounce comparison must stay in that same frame — an
            // instant diff against an offset tz would stretch the window.
            $nowWall = Carbon::parse($now->toDateTimeString());

            $lastEvent = $this->activeEvents()
                ->where('organization_id', $terminal->organization_id)
                ->where('employee_id', $employee->id)
                ->whereDate('occurred_at', $date)
                ->orderByDesc('occurred_at')
                ->orderByDesc('id')
                ->lockForUpdate()
                ->first();

            $isOpen = $lastEvent !== null
                && in_array($lastEvent->event_type, [AttendanceEvent::CHECK_IN, AttendanceEvent::MANUAL_IN, AttendanceEvent::BREAK_END], true);

            $eventType = $isOpen ? AttendanceEvent::CHECK_OUT : AttendanceEvent::CHECK_IN;

            // Debounce (§72-A): a repeat tap on the same employee+terminal within the
            // configurable window (default 15s) returns the original result instead of
            // flipping the state, so a double-tap cannot create a check-out.
            $window = $org?->debounceSeconds() ?? 15;

            $originalEvent = $this->activeEvents()
                ->where('organization_id', $terminal->organization_id)
                ->where('employee_id', $employee->id)
                ->where('terminal_id', $terminal->id)
                ->whereDate('occurred_at', $date)
                ->orderByDesc('occurred_at')
                ->orderByDesc('id')
                ->lockForUpdate()
                ->first();

            if ($originalEvent !== null
                && $originalEvent->occurred_at->greaterThan($nowWall->copy()->subSeconds($window))) {
                $daily = $this->deriveDaily($employee, $date);

                return ['event' => $originalEvent, 'daily' => $daily, 'action' => 'duplicate'];
            }

            $event = AttendanceEvent::create([
                'organization_id' => $terminal->organization_id,
                'employee_id' => $employee->id,
                'terminal_id' => $terminal->id,
                'nfc_card_id' => $card->id,
                'event_type' => $eventType,
                'occurred_at' => $now,
                'timezone' => $org?->timezone ?? 'UTC',
                'ip_address' => request()->ip(),
                'device_identifier' => $terminal->name,
                'source' => 'nfc',
            ]);

            $card->forceFill(['last_used_at' => $now])->saveQuietly();
            $terminal->forceFill(['last_seen_at' => $now])->saveQuietly();

            $daily = $this->deriveDaily($employee, $date);

            return ['event' => $event, 'daily' => $daily, 'action' => $eventType === AttendanceEvent::CHECK_IN ? 'check_in' : 'check_out'];
        });
    }

    /**
     * Record a manual attendance event and re-derive the day.
     *
     * @return array{event: AttendanceEvent, daily: DailyAttendance}
     */
    public function recordManual(
        Employee $employee,
        string $eventType,
        Carbon $occurredAt,
        string $notes,
        int $actorId,
    ): array {
        $org = $employee->organization;
        $date = $occurredAt->copy()->timezone($org?->timezone ?? 'UTC')->toDateString();

        return DB::transaction(function () use ($employee, $eventType, $occurredAt, $notes, $actorId, $date) {
            $event = AttendanceEvent::create([
                'organization_id' => $employee->organization_id,
                'employee_id' => $employee->id,
                'event_type' => $eventType,
                'occurred_at' => $occurredAt,
                'timezone' => $org?->timezone ?? 'UTC',
                'ip_address' => request()->ip(),
                'source' => 'manual',
                'notes' => $notes,
                'created_by' => $actorId,
            ]);

            $daily = $this->deriveDaily($employee, $date, isManual: true);

            return ['event' => $event, 'daily' => $daily];
        });
    }

    /**
     * Replace an existing entry with a corrected version (§24).
     *
     * The original row is never overwritten — it is archived via superseded_by
     * and both affected days are re-derived from the active event set.
     */
    public function replaceEvent(
        AttendanceEvent $event,
        string $eventType,
        Carbon $occurredAt,
        string $reason,
        int $actorId,
    ): AttendanceEvent {
        return DB::transaction(function () use ($event, $eventType, $occurredAt, $reason, $actorId) {
            $employee = Employee::withoutGlobalScopes()->findOrFail($event->employee_id);
            $oldDate = $event->occurred_at->toDateString();
            $newDate = $occurredAt->toDateString();

            $replacement = AttendanceEvent::create([
                'organization_id' => $event->organization_id,
                'employee_id' => $event->employee_id,
                'terminal_id' => $event->terminal_id,
                'nfc_card_id' => $event->nfc_card_id,
                'event_type' => $eventType,
                'occurred_at' => $occurredAt,
                'timezone' => $event->timezone,
                'ip_address' => $event->ip_address,
                'device_identifier' => $event->device_identifier,
                'source' => $event->source,
                'notes' => $reason,
                'created_by' => $actorId,
            ]);

            $event->update(['superseded_by' => $replacement->id]);

            $this->deriveDaily($employee, $oldDate);

            if ($newDate !== $oldDate) {
                $this->deriveDaily($employee, $newDate);
            }

            return $replacement;
        });
    }

    /**
     * Remove an entry (soft delete) and re-derive the affected day (§24).
     *
     * @return array{date: string, daily: DailyAttendance}
     */
    public function removeEvent(AttendanceEvent $event): array
    {
        return DB::transaction(function () use ($event) {
            $employee = Employee::withoutGlobalScopes()->findOrFail($event->employee_id);
            $date = $event->occurred_at->toDateString();

            $event->delete();

            $daily = $this->deriveDaily($employee, $date);

            return ['date' => $date, 'daily' => $daily];
        });
    }

    /**
     * Active events are the source of truth: entries that were corrected
     * (superseded_by set) or removed (soft-deleted) never feed derivation.
     * withoutGlobalScopes() also strips the soft-delete scope, so both
     * exclusions are applied explicitly.
     */
    private function activeEvents(): Builder
    {
        return AttendanceEvent::withoutGlobalScopes()
            ->whereNull('superseded_by')
            ->whereNull('deleted_at');
    }

    /**
     * Derive (or re-derive) the daily attendance row from immutable events.
     *
     * Total worked time is the sum of all completed CHECK_IN→CHECK_OUT segments
     * (§10/§72), not first-in→last-out, so mid-day exits are never counted as work.
     */
    public function deriveDaily(Employee $employee, string $date, bool $isManual = false): DailyAttendance
    {
        $org = $employee->organization;
        $tz = $org?->timezone ?? 'UTC';

        $events = $this->activeEvents()
            ->where('organization_id', $employee->organization_id)
            ->where('employee_id', $employee->id)
            ->whereDate('occurred_at', $date)
            ->orderBy('occurred_at')
            ->get();

        $schedule = WorkSchedule::effectiveFor($employee->organization_id, $date);

        $dayOfWeek = (int) Carbon::parse($date, $tz)->dayOfWeekIso;
        $isWorkDay = in_array($dayOfWeek, $schedule?->workDayNumbers() ?? [1, 2, 3, 4, 5], true);

        $isHoliday = Holiday::withoutGlobalScopes()
            ->where('organization_id', $employee->organization_id)
            ->where('date', $date)
            ->exists();

        $inTypes = [AttendanceEvent::CHECK_IN, AttendanceEvent::MANUAL_IN, AttendanceEvent::BREAK_END];
        $outTypes = [AttendanceEvent::CHECK_OUT, AttendanceEvent::MANUAL_OUT, AttendanceEvent::BREAK_START];

        $checkIn = $events->whereIn('event_type', [AttendanceEvent::CHECK_IN, AttendanceEvent::MANUAL_IN])->first();
        $checkOut = $events->whereIn('event_type', [AttendanceEvent::CHECK_OUT, AttendanceEvent::MANUAL_OUT])->last();

        // Walk events in order, pairing IN→OUT into segments (§72 state machine).
        /** @var array<int, array{in: Carbon, out: Carbon}> $segments */
        $segments = [];
        $openFrom = null;
        $reviewFlag = null;

        // All event/schedule times are naive wall-clock values in the org
        // timezone; keep every comparison in that one frame (never convert
        // to another tz, or e.g. a 10:00 check-in vs a 09:00 schedule would
        // compare as different instants).
        foreach ($events as $event) {
            $at = $event->occurred_at->copy();

            if (in_array($event->event_type, $inTypes, true)) {
                $openFrom ??= $at;
            } elseif ($openFrom !== null) {
                $segments[] = ['in' => $openFrom, 'out' => $at];
                $openFrom = null;
            }
        }

        $now = Carbon::parse(Carbon::now($tz)->toDateTimeString());
        $hasOpenSegment = false;

        if ($openFrom !== null) {
            $hasOpenSegment = true;

            if ($date < $now->toDateString()) {
                // Open segment on a past day = forgotten check-out (§72-C).
                $openEnd = Carbon::parse($date.' 23:59:59');
                $reviewFlag = 'possible_missed_checkin';
            } else {
                $openEnd = $now->copy();
            }

            $maxShift = $this->overtime->policyFor((int) $employee->organization_id, $date)
                ->max_shift_minutes ?: self::MAX_SHIFT_MINUTES;

            if ((int) $openFrom->diffInMinutes($openEnd) > $maxShift) {
                $openEnd = $openFrom->copy()->addMinutes($maxShift);
                $reviewFlag ??= 'possible_missed_checkin';
            }

            $segments[] = ['in' => $openFrom, 'out' => $openEnd];
        }

        $totalMinutes = 0;
        foreach ($segments as $segment) {
            $totalMinutes += max(0, (int) round($segment['in']->diffInMinutes($segment['out'])));
        }

        $expectedMinutes = null;

        if ($schedule) {
            $start = Carbon::parse($date.' '.$schedule->start_time);
            $end = Carbon::parse($date.' '.$schedule->end_time);
            $breakMinutes = 0;

            if ($schedule->break_start && $schedule->break_end) {
                $breakMinutes = (int) Carbon::parse($date.' '.$schedule->break_start)
                    ->diffInMinutes(Carbon::parse($date.' '.$schedule->break_end));
            }

            $expectedMinutes = max(0, (int) $start->diffInMinutes($end) - $breakMinutes);

            // Deduct the configured break only for the part not already excluded by a
            // mid-day gap (tapped-out lunch), so breaks are never counted twice.
            if ($schedule->break_start && $schedule->break_end && ! $hasOpenSegment && $segments !== []) {
                $breakStart = Carbon::parse($date.' '.$schedule->break_start);
                $breakEnd = Carbon::parse($date.' '.$schedule->break_end);
                $breakTotal = (int) $breakStart->diffInMinutes($breakEnd);
                $covered = 0;

                for ($i = 0, $n = count($segments); $i < $n - 1; $i++) {
                    $overlap = (int) min($segments[$i + 1]['in']->timestamp, $breakEnd->timestamp)
                        - (int) max($segments[$i]['out']->timestamp, $breakStart->timestamp);

                    if ($overlap > 0) {
                        $covered += (int) round($overlap / 60);
                    }
                }

                $totalMinutes = max(0, $totalMinutes - max(0, $breakTotal - $covered));
            }
        }

        $status = 'PRESENT';
        $lateMinutes = 0;
        $earlyMinutes = 0;
        $overtimeMinutes = 0;

        if ($checkIn === null && $checkOut === null) {
            $status = $isHoliday ? 'HOLIDAY' : ($isWorkDay ? 'ABSENT' : 'WEEKEND');
        } else {
            // Late applies to the first check-in of the day only (§72-B).
            if ($schedule && $checkIn) {
                $start = Carbon::parse($date.' '.$schedule->start_time);
                $grace = (int) $schedule->grace_minutes;
                $checkInAt = $checkIn->occurred_at->copy();
                if ($checkInAt->greaterThan($start->copy()->addMinutes($grace))) {
                    $lateMinutes = (int) $start->diffInMinutes($checkInAt);
                    $status = 'LATE';
                }
            }

            // Early leave / overtime apply to the last check-out only (§72-B).
            $endReference = $checkOut?->occurred_at?->copy();

            if ($endReference && $schedule) {
                $end = Carbon::parse($date.' '.$schedule->end_time);
                if ($endReference->lessThan($end)) {
                    $earlyMinutes = (int) $endReference->diffInMinutes($end);
                } elseif ($endReference->greaterThan($end)) {
                    $overtimeMinutes = (int) $end->diffInMinutes($endReference);
                }
            }

            // Unreturned exit / very short day: surface HALF_DAY + review item (§72-B).
            if ($isWorkDay && ! $isHoliday && ! $hasOpenSegment && $expectedMinutes !== null
                && $segments !== [] && ($totalMinutes * 2) < $expectedMinutes) {
                $status = 'HALF_DAY';
                $reviewFlag ??= 'possible_missed_checkin';
            }

            if (count($segments) >= self::MAX_SEGMENTS) {
                $reviewFlag ??= 'excessive_segments';
            }
        }

        $daily = DailyAttendance::withoutGlobalScopes()
            ->where('organization_id', $employee->organization_id)
            ->where('employee_id', $employee->id)
            ->where('date', $date)
            ->lockForUpdate()
            ->first();

        $previousLateMinutes = (int) ($daily?->late_minutes ?? 0);

        $attributes = [
            'first_check_in' => $checkIn?->occurred_at,
            'last_check_out' => $checkOut?->occurred_at,
            'total_work_minutes' => $totalMinutes,
            'late_minutes' => $lateMinutes,
            'early_leave_minutes' => $earlyMinutes,
            'overtime_minutes' => $overtimeMinutes,
            'segment_count' => count($segments),
            'review_flag' => $reviewFlag,
            'status' => $status,
            'is_manual' => $isManual || ($daily?->is_manual ?? false),
        ];

        if ($daily) {
            $daily->update($attributes);
            $this->notifyLateTransition($employee, $date, $lateMinutes, $previousLateMinutes);
            // §73-C: overtime is derived after every (re)derivation of the day.
            $this->overtime->recalculate($employee, $date);
            $daily->refresh();

            return $daily;
        }

        $created = DailyAttendance::withoutGlobalScopes()->create([
            'organization_id' => $employee->organization_id,
            'employee_id' => $employee->id,
            'date' => $date,
            ...$attributes,
        ]);
        $this->notifyLateTransition($employee, $date, $lateMinutes, 0);
        $this->overtime->recalculate($employee, $date);
        $created->refresh();

        return $created;
    }

    /**
     * §25 "Late arrival" — fires once when a day first accrues late minutes,
     * not on every re-derivation of that day.
     */
    private function notifyLateTransition(Employee $employee, string $date, int $lateMinutes, int $previousLateMinutes): void
    {
        if ($lateMinutes <= 0 || $previousLateMinutes > 0) {
            return;
        }

        $employee->user?->notify(new LateArrival($employee->id, $date, $lateMinutes));
    }
}
