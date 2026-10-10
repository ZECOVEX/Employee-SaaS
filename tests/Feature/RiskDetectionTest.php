<?php

namespace Tests\Feature;

use App\Models\AttendanceEvent;
use App\Models\DailyAttendance;
use App\Models\Employee;
use App\Models\Organization;
use App\Models\RiskEvent;
use App\Models\Role;
use App\Notifications\HighRiskAttendance;
use App\Notifications\OvertimeLimitNotice;
use App\Services\AttendanceService;
use App\Services\RiskService;
use App\Services\WorkforceDefaults;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\CreatesOrganizations;
use Tests\TestCase;

/**
 * Phase 5 M5b — §15 risk detection: the weekly late-risk rule (threshold,
 * window, level, recipients, suppression — all configurable) and the [OT]
 * overwork notices (weekly / monthly / consecutive-days, default off).
 * Overtime notices are informational and never touch the score.
 */
class RiskDetectionTest extends TestCase
{
    use CreatesOrganizations;
    use RefreshDatabase;

    private Organization $organization;

    /** @var array<string, Role> */
    private array $roles;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-09-30 23:00:00'); // Wednesday of the ISO week Sep 28 – Oct 4

        $ctx = $this->makeOrganization('Risk '.uniqid());
        $this->organization = $ctx['organization'];
        $this->roles = $ctx['roles'];
    }

    public function test_weekly_late_risk_fires_once_at_the_threshold(): void
    {
        Notification::fake();

        $employee = $this->employeeWithDefaults();
        $employeeUser = $employee->user;
        $hrUser = $this->makeUser($this->organization, 'hr', $this->roles);

        // Two late days: below the default threshold of 3 → nothing happens.
        $this->lateDay($employee, '2026-09-28');
        $this->lateDay($employee, '2026-09-29');

        $this->assertSame(0, RiskEvent::withoutGlobalScopes()->count());

        // Third late day inside the same calendar week triggers the rule.
        $this->lateDay($employee, '2026-09-30');

        $event = RiskEvent::withoutGlobalScopes()->sole();

        $this->assertSame(RiskEvent::TYPE_LATE_WEEK, $event->type);
        $this->assertSame('HIGH RISK', $event->level);
        $this->assertSame(3, $event->observed);
        $this->assertSame(3, $event->threshold);
        $this->assertSame('2026-09-28', $event->period_start->toDateString());
        $this->assertSame('2026-10-04', $event->period_end->toDateString());
        $this->assertSame('Late arrivals this week: 3', $event->detail);
        $this->assertNotNull($event->notified_at);

        // Default recipients: employee + HR (manager not configured).
        Notification::assertSentTo($employeeUser, HighRiskAttendance::class);
        Notification::assertSentTo($hrUser, HighRiskAttendance::class);

        // Suppression: re-deriving the day does not notify again.
        app(AttendanceService::class)->deriveDaily($employee, '2026-09-30');
        Notification::assertSentToTimes($employeeUser, HighRiskAttendance::class, 1);
        $this->assertSame(1, RiskEvent::withoutGlobalScopes()->count());
    }

    public function test_window_and_disabled_settings_are_configurable(): void
    {
        Notification::fake();

        $employee = $this->employeeWithDefaults();

        // Late on Thu Sep 24, Fri Sep 25 and Mon Sep 28 — only Sep 28 falls
        // inside the calendar week of Sep 30, so the default window sees 1.
        $this->lateDay($employee, '2026-09-24');
        $this->lateDay($employee, '2026-09-25');
        $this->lateDay($employee, '2026-09-28');

        $this->assertSame(0, RiskEvent::withoutGlobalScopes()->count());
        Notification::assertNotSentTo($employee->user, HighRiskAttendance::class);

        // Rolling 7 days (Sep 24 – Sep 30) sees all three.
        $this->updateSettings(['late_risk_period' => 'rolling_7']);
        $employee->unsetRelation('organization');

        $event = app(RiskService::class)->evaluateLateRisk($employee, '2026-09-30');

        $this->assertNotNull($event);
        $this->assertSame('2026-09-24', $event->period_start->toDateString());
        $this->assertSame('2026-09-30', $event->period_end->toDateString());
        Notification::assertSentTo($employee->user, HighRiskAttendance::class);

        // Disabled rule never fires.
        Notification::fake();
        $this->updateSettings(['late_risk_enabled' => false, 'late_risk_period' => 'calendar_week']);
        $employee->unsetRelation('organization');

        $this->assertNull(app(RiskService::class)->evaluateLateRisk($employee, '2026-09-30'));
        $this->assertSame(1, RiskEvent::withoutGlobalScopes()->count()); // the rolling one stays
        Notification::assertNotSentTo($employee->user, HighRiskAttendance::class);
    }

    public function test_custom_threshold_level_and_recipients(): void
    {
        Notification::fake();

        $employee = $this->employeeWithDefaults();
        $managerUser = $this->makeUser($this->organization, 'manager', $this->roles);
        $employee->update(['manager_id' => $managerUser->id]);
        $hrUser = $this->makeUser($this->organization, 'hr', $this->roles);

        $this->updateSettings([
            'late_risk_threshold' => 2,
            'late_risk_level' => 'CRITICAL',
            'late_risk_recipients' => ['employee', 'manager'],
        ]);

        $this->lateDay($employee, '2026-09-28');
        $this->lateDay($employee, '2026-09-29');

        $event = RiskEvent::withoutGlobalScopes()->sole();

        $this->assertSame('CRITICAL', $event->level);
        $this->assertSame(2, $event->observed);
        $this->assertSame(2, $event->threshold);

        Notification::assertSentTo($employee->user, HighRiskAttendance::class);
        Notification::assertSentTo($managerUser, HighRiskAttendance::class);
        Notification::assertNotSentTo($hrUser, HighRiskAttendance::class);
    }

    public function test_suppression_can_be_disabled_to_allow_repeats(): void
    {
        Notification::fake();

        $employee = $this->employeeWithDefaults();
        $this->updateSettings(['late_risk_suppress' => false]);

        $this->lateDay($employee, '2026-09-28');
        $this->lateDay($employee, '2026-09-29');
        $this->lateDay($employee, '2026-09-30');

        Notification::assertSentToTimes($employee->user, HighRiskAttendance::class, 1);

        // Same window again → with suppression off, the notice repeats.
        app(AttendanceService::class)->deriveDaily($employee, '2026-09-30');

        Notification::assertSentToTimes($employee->user, HighRiskAttendance::class, 2);
        $this->assertSame(1, RiskEvent::withoutGlobalScopes()->count());
    }

    public function test_overtime_overwork_notices_are_informational(): void
    {
        Notification::fake();

        $employee = $this->employeeWithDefaults();
        $hrUser = $this->makeUser($this->organization, 'hr', $this->roles);

        $this->updateSettings([
            'ot_risk_enabled' => true,
            'ot_risk_weekly_minutes' => 120,
            'ot_risk_monthly_minutes' => 180,
            'ot_risk_streak_days' => 3,
        ]);

        // Three days × 60 overtime minutes (checkout 19:00).
        foreach (['2026-09-28', '2026-09-29', '2026-09-30'] as $date) {
            $this->seedDay($employee, $date, '09:00', '19:00');
        }

        $events = RiskEvent::withoutGlobalScopes()
            ->where('employee_id', $employee->id)
            ->get()
            ->keyBy('type');

        $this->assertCount(3, $events);
        $this->assertSame(180, $events[RiskEvent::TYPE_OT_WEEK]->observed);
        $this->assertSame(120, $events[RiskEvent::TYPE_OT_WEEK]->threshold);
        $this->assertSame('Overtime this week: 3h', $events[RiskEvent::TYPE_OT_WEEK]->detail);
        $this->assertSame(180, $events[RiskEvent::TYPE_OT_MONTH]->observed);
        $this->assertSame('2026-09-01', $events[RiskEvent::TYPE_OT_MONTH]->period_start->toDateString());
        $this->assertSame(3, $events[RiskEvent::TYPE_OT_STREAK]->observed);
        $this->assertSame('2026-09-28', $events[RiskEvent::TYPE_OT_STREAK]->period_start->toDateString());
        $this->assertSame('Overtime on 3 consecutive days', $events[RiskEvent::TYPE_OT_STREAK]->detail);
        $this->assertSame('NOTICE', $events[RiskEvent::TYPE_OT_WEEK]->level);

        // One notice per breached rule, for employee and HR.
        Notification::assertSentToTimes($employee->user, OvertimeLimitNotice::class, 3);
        Notification::assertSentToTimes($hrUser, OvertimeLimitNotice::class, 3);

        Notification::assertSentTo($employee->user, OvertimeLimitNotice::class, function (OvertimeLimitNotice $notice) {
            return str_contains($notice->body(), 'exceeded the configured weekly limit (2h)');
        });
    }

    public function test_overtime_notice_defaults_to_off(): void
    {
        Notification::fake();

        $employee = $this->employeeWithDefaults();

        foreach (['2026-09-28', '2026-09-29', '2026-09-30'] as $date) {
            $this->seedDay($employee, $date, '09:00', '19:00');
        }

        $this->assertSame(0, RiskEvent::withoutGlobalScopes()->count());
        Notification::assertNotSentTo($employee->user, OvertimeLimitNotice::class);
    }

    public function test_daily_sweep_raises_events_for_configured_rules(): void
    {
        Notification::fake();

        $employee = $this->employeeWithDefaults();
        $this->updateSettings(['late_risk_enabled' => false]);

        $this->lateDay($employee, '2026-09-28');
        $this->lateDay($employee, '2026-09-29');
        $this->lateDay($employee, '2026-09-30');
        $this->assertSame(0, RiskEvent::withoutGlobalScopes()->count());

        // Admin enables the rule later — the nightly sweep catches up.
        $this->updateSettings(['late_risk_enabled' => true]);

        $this->artisan('risk:evaluate', ['--date' => '2026-09-30'])
            ->assertSuccessful();

        $event = RiskEvent::withoutGlobalScopes()->sole();
        $this->assertSame(RiskEvent::TYPE_LATE_WEEK, $event->type);
        $this->assertSame(3, $event->observed);
        Notification::assertSentTo($employee->user, HighRiskAttendance::class);
    }

    private function employeeWithDefaults(): Employee
    {
        app(WorkforceDefaults::class)->apply($this->organization);
        $user = $this->makeUser($this->organization, 'employee', $this->roles);

        return $this->makeEmployee($this->organization, $user, 'EMP-RISK-'.uniqid());
    }

    private function lateDay(Employee $employee, string $date): DailyAttendance
    {
        return $this->seedDay($employee, $date, '09:30', '18:00');
    }

    private function seedDay(Employee $employee, string $date, string $in, string $out): DailyAttendance
    {
        foreach ([[AttendanceEvent::CHECK_IN, $in], [AttendanceEvent::CHECK_OUT, $out]] as [$type, $time]) {
            AttendanceEvent::withoutGlobalScopes()->create([
                'organization_id' => $employee->organization_id,
                'employee_id' => $employee->id,
                'event_type' => $type,
                'occurred_at' => Carbon::parse($date.' '.$time),
                'timezone' => 'UTC',
                'source' => 'manual',
                'notes' => 'seed',
                'created_by' => $employee->user_id,
            ]);
        }

        return app(AttendanceService::class)->deriveDaily($employee, $date);
    }

    /** @param  array<string, mixed>  $changes */
    private function updateSettings(array $changes): void
    {
        $this->organization->refresh();
        $this->organization->update([
            'settings' => array_merge($this->organization->settings ?? [], $changes),
        ]);
    }
}
