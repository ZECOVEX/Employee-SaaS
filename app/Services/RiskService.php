<?php

namespace App\Services;

use App\Models\DailyAttendance;
use App\Models\Employee;
use App\Models\Organization;
use App\Models\OvertimeRecord;
use App\Models\RiskEvent;
use App\Models\User;
use App\Notifications\HighRiskAttendance;
use App\Notifications\OvertimeLimitNotice;
use App\Support\Tenancy\OrganizationScope;
use Carbon\Carbon;

/**
 * §15 employee risk: thresholds, status labels, score weights and the
 * risk rules are read from organization settings — the spec's example
 * values are fallbacks, never hardcoded at call sites.
 *
 * Two detection rules run from attendance/overtime derivation and the
 * daily sweep:
 *  - Weekly late-risk: >= configured late days in the configured window
 *    creates a high-risk event + notification (recipients & suppression
 *    configurable).
 *  - [OT] overwork notice (default off): weekly/monthly minutes or a
 *    consecutive-days streak over the configured limit. Informational
 *    only — it never reduces the score.
 */
class RiskService
{
    /** Recipient keys the late-risk / overwork rules can notify. */
    public const RECIPIENTS = ['employee', 'manager', 'hr'];

    /** Evaluation windows for the weekly late-risk rule. */
    public const PERIODS = ['calendar_week', 'rolling_7'];

    /**
     * Spec §15 example defaults — used until an admin saves their own.
     *
     * @return array<string, mixed>
     */
    public static function defaults(): array
    {
        return [
            'score_weight_attendance' => 40.0,
            'score_weight_punctuality' => 25.0,
            'score_weight_working_hours' => 20.0,
            'score_weight_leave' => 15.0,
            'risk_bands' => [
                ['min' => 90, 'label' => 'Excellent'],
                ['min' => 80, 'label' => 'Good'],
                ['min' => 70, 'label' => 'Needs Attention'],
                ['min' => 0, 'label' => 'At Risk'],
            ],
            'late_risk_enabled' => true,
            'late_risk_threshold' => 3,
            'late_risk_period' => 'calendar_week',
            'late_risk_level' => 'HIGH RISK',
            'late_risk_recipients' => ['employee', 'hr'],
            'late_risk_suppress' => true,
            'ot_risk_enabled' => false,
            'ot_risk_weekly_minutes' => 720,
            'ot_risk_monthly_minutes' => null,
            'ot_risk_streak_days' => null,
            'ot_risk_level' => 'NOTICE',
            'ot_risk_recipients' => ['employee', 'hr'],
            'ot_risk_suppress' => true,
        ];
    }

    /**
     * Effective config for an organization: stored settings over the
     * defaults, normalized to safe types.
     *
     * @return array<string, mixed>
     */
    public function config(Organization $organization): array
    {
        $settings = $organization->settings ?? [];
        $defaults = self::defaults();

        $config = [];
        foreach ($defaults as $key => $default) {
            $value = $settings[$key] ?? $default;
            $config[$key] = $value ?? $default;
        }

        $bands = [];
        foreach ((array) $config['risk_bands'] as $band) {
            if (is_array($band) && isset($band['min'], $band['label'])) {
                $bands[] = [
                    'min' => (int) $band['min'],
                    'label' => (string) $band['label'],
                ];
            }
        }

        if ($bands === []) {
            $bands = $defaults['risk_bands'];
        }

        usort($bands, fn (array $a, array $b): int => $b['min'] <=> $a['min']);
        $config['risk_bands'] = $bands;

        $config['score_weight_attendance'] = (float) $config['score_weight_attendance'];
        $config['score_weight_punctuality'] = (float) $config['score_weight_punctuality'];
        $config['score_weight_working_hours'] = (float) $config['score_weight_working_hours'];
        $config['score_weight_leave'] = (float) $config['score_weight_leave'];
        $config['late_risk_enabled'] = (bool) $config['late_risk_enabled'];
        $config['late_risk_threshold'] = max(1, (int) $config['late_risk_threshold']);
        $config['late_risk_period'] = in_array($config['late_risk_period'], self::PERIODS, true)
            ? $config['late_risk_period'] : 'calendar_week';
        $config['late_risk_level'] = (string) $config['late_risk_level'];
        $config['late_risk_recipients'] = $this->recipientList($config['late_risk_recipients']);
        $config['late_risk_suppress'] = (bool) $config['late_risk_suppress'];
        $config['ot_risk_enabled'] = (bool) $config['ot_risk_enabled'];
        $config['ot_risk_weekly_minutes'] = max(60, (int) $config['ot_risk_weekly_minutes']);
        $config['ot_risk_monthly_minutes'] = $config['ot_risk_monthly_minutes'] !== null
            ? max(60, (int) $config['ot_risk_monthly_minutes']) : null;
        $config['ot_risk_streak_days'] = $config['ot_risk_streak_days'] !== null
            ? max(2, (int) $config['ot_risk_streak_days']) : null;
        $config['ot_risk_level'] = (string) $config['ot_risk_level'];
        $config['ot_risk_recipients'] = $this->recipientList($config['ot_risk_recipients']);
        $config['ot_risk_suppress'] = (bool) $config['ot_risk_suppress'];

        return $config;
    }

    /**
     * The status band for a final score (§15 example: ≥90 Excellent …
     * <70 At Risk) — first band whose minimum the score meets wins.
     *
     * @return array{label: string, min: int}
     */
    public function statusFor(Organization $organization, float $score): array
    {
        $bands = $this->config($organization)['risk_bands'];

        foreach ($bands as $band) {
            if ($score >= $band['min']) {
                return ['label' => $band['label'], 'min' => $band['min']];
            }
        }

        $lowest = end($bands);

        return ['label' => $lowest['label'], 'min' => $lowest['min']];
    }

    /**
     * §15 weekly late-risk rule: >= threshold late days within the
     * configured window creates one event per window (unique key) and
     * notifies the configured recipients.
     */
    public function evaluateLateRisk(Employee $employee, string $asOf): ?RiskEvent
    {
        $organization = $employee->organization;

        if (! $organization) {
            return null;
        }

        $config = $this->config($organization);

        if (! $config['late_risk_enabled']) {
            return null;
        }

        $tz = $organization->timezone ?? 'UTC';
        [$start, $end] = $this->lateWindow($config['late_risk_period'], $asOf, $tz);

        $lateDays = DailyAttendance::withoutGlobalScopes()
            ->where('employee_id', $employee->id)
            ->whereBetween('date', [$start, $end])
            ->where(fn ($query) => $query->where('late_minutes', '>', 0)->orWhere('status', 'LATE'))
            ->count();

        if ($lateDays < $config['late_risk_threshold']) {
            return null;
        }

        $window = $config['late_risk_period'] === 'rolling_7' ? 'in the last 7 days' : 'this week';

        [$event, $notify] = $this->record(
            $employee,
            RiskEvent::TYPE_LATE_WEEK,
            $start,
            $end,
            $lateDays,
            $config['late_risk_threshold'],
            $config['late_risk_level'],
            "Late arrivals {$window}: {$lateDays}",
            $config['late_risk_suppress'],
        );

        if ($notify) {
            $this->notify($employee, $event, $config['late_risk_recipients'], 'late', null);
        }

        return $event;
    }

    /**
     * §15 [OT] overwork notice (default off): weekly / monthly minutes or
     * a consecutive-days streak over the configured limit.
     *
     * @return list<RiskEvent> every event that is currently breached
     */
    public function evaluateOvertimeRisk(Employee $employee, string $asOf): array
    {
        $organization = $employee->organization;

        if (! $organization) {
            return [];
        }

        $config = $this->config($organization);

        if (! $config['ot_risk_enabled']) {
            return [];
        }

        $tz = $organization->timezone ?? 'UTC';
        $date = Carbon::parse($asOf, $tz);
        $events = [];
        $pending = [];

        $weekStart = $date->copy()->startOfWeek()->toDateString();
        $weekEnd = $date->copy()->endOfWeek()->toDateString();
        $weekly = $this->overtimeMinutes($employee, $weekStart, $weekEnd);

        if ($weekly >= $config['ot_risk_weekly_minutes']) {
            [$event, $notify] = $this->record(
                $employee,
                RiskEvent::TYPE_OT_WEEK,
                $weekStart,
                $weekEnd,
                $weekly,
                $config['ot_risk_weekly_minutes'],
                $config['ot_risk_level'],
                'Overtime this week: '.OvertimeRecord::minutesLabelFor($weekly),
                $config['ot_risk_suppress'],
            );
            $events[] = $event;
            if ($notify) {
                $pending[] = [$event, 'weekly limit ('.OvertimeRecord::minutesLabelFor($config['ot_risk_weekly_minutes']).')'];
            }
        }

        if ($config['ot_risk_monthly_minutes'] !== null) {
            $monthStart = $date->copy()->startOfMonth()->toDateString();
            $monthEnd = $date->copy()->endOfMonth()->toDateString();
            $monthly = $this->overtimeMinutes($employee, $monthStart, $monthEnd);

            if ($monthly >= $config['ot_risk_monthly_minutes']) {
                [$event, $notify] = $this->record(
                    $employee,
                    RiskEvent::TYPE_OT_MONTH,
                    $monthStart,
                    $monthEnd,
                    $monthly,
                    $config['ot_risk_monthly_minutes'],
                    $config['ot_risk_level'],
                    'Overtime this month: '.OvertimeRecord::minutesLabelFor($monthly),
                    $config['ot_risk_suppress'],
                );
                $events[] = $event;
                if ($notify) {
                    $pending[] = [$event, 'monthly limit ('.OvertimeRecord::minutesLabelFor($config['ot_risk_monthly_minutes']).')'];
                }
            }
        }

        if ($config['ot_risk_streak_days'] !== null) {
            $limit = $config['ot_risk_streak_days'];
            $streak = $this->longestOvertimeStreak($employee, $asOf, $limit);

            if ($streak !== null && $streak['length'] >= $limit) {
                // Fixed window: the first `limit` days of the run, so a
                // continuing streak keeps one suppressible event.
                $windowStart = $streak['start'];
                $windowEnd = Carbon::parse($streak['start'])->addDays($limit - 1)->toDateString();

                [$event, $notify] = $this->record(
                    $employee,
                    RiskEvent::TYPE_OT_STREAK,
                    $windowStart,
                    $windowEnd,
                    $streak['length'],
                    $limit,
                    $config['ot_risk_level'],
                    'Overtime on '.$streak['length'].' consecutive days',
                    $config['ot_risk_suppress'],
                );
                $events[] = $event;
                if ($notify) {
                    $pending[] = [$event, "streak ({$limit} days)"];
                }
            }
        }

        foreach ($pending as [$event, $limitText]) {
            $this->notify($employee, $event, $config['ot_risk_recipients'], 'overtime', $limitText);
        }

        return $events;
    }

    /**
     * Both rules — used by the daily `risk:evaluate` sweep.
     *
     * @return array{late: ?RiskEvent, overtime: list<RiskEvent>}
     */
    public function evaluate(Employee $employee, string $asOf): array
    {
        return [
            'late' => $this->evaluateLateRisk($employee, $asOf),
            'overtime' => $this->evaluateOvertimeRisk($employee, $asOf),
        ];
    }

    /**
     * Upsert the event for this window. Returns the event plus whether the
     * recipients should be notified (new event, or repeats not suppressed).
     *
     * @return array{0: RiskEvent, 1: bool}
     */
    private function record(
        Employee $employee,
        string $type,
        string $start,
        string $end,
        int $observed,
        int $threshold,
        string $level,
        string $detail,
        bool $suppress,
    ): array {
        $event = RiskEvent::withoutGlobalScopes()->firstOrNew([
            'organization_id' => $employee->organization_id,
            'employee_id' => $employee->id,
            'type' => $type,
            'period_start' => $start,
            'period_end' => $end,
        ]);

        $notify = ! $event->exists || ! $suppress;

        $event->fill([
            'level' => $level,
            'observed' => $observed,
            'threshold' => $threshold,
            'detail' => $detail,
            'notified_at' => $notify ? now() : $event->notified_at,
        ]);
        $event->save();

        return [$event, $notify];
    }

    /**
     * §15 recipients: the employee, their manager (a User) and/or HR role
     * holders — each gets the audience-appropriate wording.
     *
     * @param  list<string>  $recipients
     */
    private function notify(
        Employee $employee,
        RiskEvent $event,
        array $recipients,
        string $rule,
        ?string $limitText,
    ): void {
        $employeeName = $employee->user?->name ?? 'Employee '.$employee->employee_code;
        $targets = [];

        if (in_array('employee', $recipients, true) && $employee->user) {
            $targets[] = [$employee->user, 'employee'];
        }

        if (in_array('manager', $recipients, true) && $employee->manager) {
            $targets[] = [$employee->manager, 'manager'];
        }

        if (in_array('hr', $recipients, true)) {
            foreach ($this->hrUsers($employee) as $hrUser) {
                $targets[] = [$hrUser, 'hr'];
            }
        }

        foreach ($targets as [$user, $audience]) {
            if ($rule === 'late') {
                $user->notify(new HighRiskAttendance(
                    $employee->id,
                    $employeeName,
                    $event->detail,
                    (int) $event->threshold,
                    $event->level,
                    $audience,
                ));
            } else {
                $user->notify(new OvertimeLimitNotice(
                    $employee->id,
                    $employeeName,
                    $event->level,
                    $event->detail,
                    (string) $limitText,
                    $audience,
                ));
            }
        }
    }

    /**
     * Late-risk evaluation window: calendar week (Mon–Sun) or rolling 7 days.
     *
     * @return array{0: string, 1: string}
     */
    private function lateWindow(string $period, string $asOf, string $tz): array
    {
        $date = Carbon::parse($asOf, $tz);

        if ($period === 'rolling_7') {
            return [$date->copy()->subDays(6)->toDateString(), $date->toDateString()];
        }

        return [
            $date->copy()->startOfWeek()->toDateString(),
            $date->copy()->endOfWeek()->toDateString(),
        ];
    }

    /** Countable (approved/auto + pending) overtime minutes in a window. */
    private function overtimeMinutes(Employee $employee, string $from, string $to): int
    {
        return (int) OvertimeRecord::withoutGlobalScopes()
            ->where('employee_id', $employee->id)
            ->whereBetween('work_date', [$from, $to])
            ->whereIn('status', [
                OvertimeRecord::STATUS_APPROVED,
                OvertimeRecord::STATUS_AUTO_APPROVED,
                OvertimeRecord::STATUS_PENDING,
                OvertimeRecord::STATUS_EXPIRED,
            ])
            ->sum('countable_minutes');
    }

    /**
     * Longest run of consecutive overtime days ending within the last
     * 2×limit days (so a run that finished yesterday is still caught by
     * the sweep).
     *
     * @return array{length: int, start: string}|null
     */
    private function longestOvertimeStreak(Employee $employee, string $asOf, int $limit): ?array
    {
        $from = Carbon::parse($asOf)->subDays($limit * 2)->toDateString();

        $dates = OvertimeRecord::withoutGlobalScopes()
            ->where('employee_id', $employee->id)
            ->whereBetween('work_date', [$from, $asOf])
            ->whereIn('status', [
                OvertimeRecord::STATUS_APPROVED,
                OvertimeRecord::STATUS_AUTO_APPROVED,
                OvertimeRecord::STATUS_PENDING,
                OvertimeRecord::STATUS_EXPIRED,
            ])
            ->where('countable_minutes', '>', 0)
            ->pluck('work_date')
            ->map(fn ($date) => $date->toDateString())
            ->unique()
            ->sort()
            ->values();

        if ($dates->isEmpty()) {
            return null;
        }

        $best = 0;
        $bestStart = $dates[0];
        $run = 0;
        $runStart = $dates[0];
        $previous = null;

        foreach ($dates as $dateString) {
            $current = Carbon::parse($dateString);

            if ($previous !== null && $current->equalTo($previous->copy()->addDay())) {
                $run++;
            } else {
                $run = 1;
                $runStart = $dateString;
            }

            if ($run > $best) {
                $best = $run;
                $bestStart = $runStart;
            }

            $previous = $current;
        }

        return ['length' => $best, 'start' => $bestStart];
    }

    /** @return list<User> */
    private function hrUsers(Employee $employee): array
    {
        return User::query()
            ->where('organization_id', $employee->organization_id)
            ->whereHas('roles', fn ($query) => $query
                ->withoutGlobalScope(OrganizationScope::class)
                ->where('slug', 'hr'))
            ->get()
            ->all();
    }

    /**
     * @param  mixed  $value
     * @return list<string>
     */
    private function recipientList($value): array
    {
        $list = is_array($value)
            ? array_values(array_intersect($value, self::RECIPIENTS))
            : [];

        return $list === [] ? ['employee'] : $list;
    }
}
