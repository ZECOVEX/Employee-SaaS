<?php

namespace App\Services;

use App\Models\AttendanceEvent;
use App\Models\AttendanceTerminal;
use App\Models\DailyAttendance;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\NfcCard;
use App\Models\WorkSchedule;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class AttendanceService
{
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

            $lastEvent = AttendanceEvent::withoutGlobalScopes()
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

            // Debounce: ignore duplicate same-direction punches within 60 seconds.
            if ($lastEvent !== null
                && $lastEvent->event_type === $eventType
                && $lastEvent->occurred_at->diffInSeconds($now) < 60) {
                $daily = $this->deriveDaily($employee, $date);

                return ['event' => $lastEvent, 'daily' => $daily, 'action' => 'duplicate'];
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
     * Derive (or re-derive) the daily attendance row from immutable events.
     */
    public function deriveDaily(Employee $employee, string $date, bool $isManual = false): DailyAttendance
    {
        $org = $employee->organization;
        $tz = $org?->timezone ?? 'UTC';

        $events = AttendanceEvent::withoutGlobalScopes()
            ->where('organization_id', $employee->organization_id)
            ->where('employee_id', $employee->id)
            ->whereDate('occurred_at', $date)
            ->orderBy('occurred_at')
            ->get();

        $schedule = WorkSchedule::withoutGlobalScopes()
            ->where('organization_id', $employee->organization_id)
            ->orderByDesc('is_default')
            ->first();

        $dayOfWeek = (int) Carbon::parse($date, $tz)->dayOfWeekIso;
        $isWorkDay = in_array($dayOfWeek, $schedule?->workDayNumbers() ?? [1, 2, 3, 4, 5], true);

        $isHoliday = Holiday::withoutGlobalScopes()
            ->where('organization_id', $employee->organization_id)
            ->where('date', $date)
            ->exists();

        $checkIn = $events->whereIn('event_type', [AttendanceEvent::CHECK_IN, AttendanceEvent::MANUAL_IN])->first();
        $checkOut = $events->whereIn('event_type', [AttendanceEvent::CHECK_OUT, AttendanceEvent::MANUAL_OUT])->last();

        $status = 'PRESENT';
        $lateMinutes = 0;
        $earlyMinutes = 0;
        $totalMinutes = 0;
        $overtimeMinutes = 0;

        if ($checkIn === null && $checkOut === null) {
            $status = $isHoliday ? 'HOLIDAY' : ($isWorkDay ? 'ABSENT' : 'WEEKEND');
        } else {
            if ($schedule && $checkIn) {
                $start = Carbon::parse($date.' '.$schedule->start_time, $tz);
                $grace = (int) $schedule->grace_minutes;
                if ($checkIn->copy()->timezone($tz)->greaterThan($start->copy()->addMinutes($grace))) {
                    $lateMinutes = $start->diffInMinutes($checkIn->copy()->timezone($tz));
                    $status = 'LATE';
                }
            }

            $endReference = $checkOut?->copy()->timezone($tz);

            if ($endReference && $schedule) {
                $end = Carbon::parse($date.' '.$schedule->end_time, $tz);
                if ($endReference->lessThan($end)) {
                    $earlyMinutes = $endReference->diffInMinutes($end);
                } elseif ($endReference->greaterThan($end)) {
                    $overtimeMinutes = $end->diffInMinutes($endReference);
                }
            }

            $workEnd = $endReference ?? Carbon::now($tz);
            $totalMinutes = max(0, (int) $checkIn->copy()->timezone($tz)->diffInMinutes($workEnd));

            if ($schedule?->break_start && $schedule?->break_end && $checkOut) {
                $breakMinutes = (int) Carbon::parse($schedule->break_start, $tz)
                    ->diffInMinutes(Carbon::parse($schedule->break_end, $tz));
                $totalMinutes = max(0, $totalMinutes - $breakMinutes);
            }
        }

        $daily = DailyAttendance::withoutGlobalScopes()
            ->where('organization_id', $employee->organization_id)
            ->where('employee_id', $employee->id)
            ->where('date', $date)
            ->lockForUpdate()
            ->first();

        $attributes = [
            'first_check_in' => $checkIn?->occurred_at,
            'last_check_out' => $checkOut?->occurred_at,
            'total_work_minutes' => $totalMinutes,
            'late_minutes' => $lateMinutes,
            'early_leave_minutes' => $earlyMinutes,
            'overtime_minutes' => $overtimeMinutes,
            'status' => $status,
            'is_manual' => $isManual || ($daily?->is_manual ?? false),
        ];

        if ($daily) {
            $daily->update($attributes);

            return $daily;
        }

        return DailyAttendance::withoutGlobalScopes()->create([
            'organization_id' => $employee->organization_id,
            'employee_id' => $employee->id,
            'date' => $date,
            ...$attributes,
        ]);
    }
}
