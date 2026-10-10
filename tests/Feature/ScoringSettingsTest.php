<?php

namespace Tests\Feature;

use App\Models\AttendanceEvent;
use App\Models\AuditLog;
use App\Models\DailyAttendance;
use App\Models\Employee;
use App\Models\Organization;
use App\Models\Role;
use App\Services\AttendanceService;
use App\Services\RiskService;
use App\Services\ScoreService;
use App\Services\WorkforceDefaults;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesOrganizations;
use Tests\TestCase;

/**
 * Phase 5 M5a — scoring & risk settings (§15): thresholds, labels, weights
 * and the weekly late-risk rule are admin-configurable, never hardcoded.
 */
class ScoringSettingsTest extends TestCase
{
    use CreatesOrganizations;
    use RefreshDatabase;

    private Organization $organization;

    /** @var array<string, Role> */
    private array $roles;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-09-30 23:00:00');

        $ctx = $this->makeOrganization('Scoring '.uniqid());
        $this->organization = $ctx['organization'];
        $this->roles = $ctx['roles'];
    }

    public function test_page_requires_settings_permission(): void
    {
        $admin = $this->makeUser($this->organization, 'company_admin', $this->roles);
        $employee = $this->makeUser($this->organization, 'employee', $this->roles);

        $this->actingAs($admin)
            ->get(route('scoring-settings.edit'))
            ->assertOk()
            ->assertSee('Score weights')
            ->assertSee('Weekly late-risk rule')
            ->assertSee('Overtime overwork notice');

        $this->actingAs($employee)
            ->get(route('scoring-settings.edit'))
            ->assertForbidden();
    }

    public function test_admin_can_configure_weights_bands_and_risk_rules(): void
    {
        $admin = $this->makeUser($this->organization, 'company_admin', $this->roles);

        $this->actingAs($admin)
            ->put(route('scoring-settings.update'), [
                'score_weight_attendance' => 50,
                'score_weight_punctuality' => 20,
                'score_weight_working_hours' => 20,
                'score_weight_leave' => 10,
                'risk_bands' => [
                    ['min' => 95, 'label' => 'Outstanding'],
                    ['min' => 85, 'label' => 'Solid'],
                    ['min' => 60, 'label' => 'Watch'],
                    ['min' => 0, 'label' => 'Escalate'],
                ],
                'late_risk_enabled' => '1',
                'late_risk_threshold' => 4,
                'late_risk_period' => 'rolling_7',
                'late_risk_level' => 'CRITICAL',
                'late_risk_recipients' => ['employee', 'manager', 'hr'],
                'late_risk_suppress' => '1',
                'ot_risk_enabled' => '1',
                'ot_risk_weekly_minutes' => 480,
                'ot_risk_monthly_minutes' => 1500,
                'ot_risk_streak_days' => 5,
                'ot_risk_level' => 'OT NOTICE',
                'ot_risk_recipients' => ['employee'],
                'ot_risk_suppress' => '0',
            ])
            ->assertRedirect(route('scoring-settings.edit'));

        $this->organization->refresh();
        $settings = $this->organization->settings ?? [];

        $this->assertEquals(50, $settings['score_weight_attendance']);
        $this->assertSame('Outstanding', $settings['risk_bands'][0]['label']);
        $this->assertSame(4, $settings['late_risk_threshold']);
        $this->assertSame('rolling_7', $settings['late_risk_period']);
        $this->assertTrue($settings['ot_risk_enabled'] === true || $settings['ot_risk_enabled'] === 1);
        $this->assertSame(480, $settings['ot_risk_weekly_minutes']);

        $risk = app(RiskService::class);
        $config = $risk->config($this->organization);
        $this->assertSame(4, $config['late_risk_threshold']);
        $this->assertSame('CRITICAL', $config['late_risk_level']);
        $this->assertSame(['employee', 'manager', 'hr'], $config['late_risk_recipients']);
        $this->assertSame(5, $config['ot_risk_streak_days']);

        // Custom bands drive the status label (§15).
        $this->assertSame('Outstanding', $risk->statusFor($this->organization, 95.0)['label']);
        $this->assertSame('Solid', $risk->statusFor($this->organization, 90.0)['label']);
        $this->assertSame('Watch', $risk->statusFor($this->organization, 60.0)['label']);
        $this->assertSame('Escalate', $risk->statusFor($this->organization, 59.99)['label']);

        $this->assertSame(1, AuditLog::withoutGlobalScopes()
            ->where('organization_id', $this->organization->id)
            ->where('action', 'scoring_settings.updated')
            ->count());
    }

    public function test_thresholds_must_descend_and_lowest_band_starts_at_zero(): void
    {
        $admin = $this->makeUser($this->organization, 'company_admin', $this->roles);

        $payload = [
            'score_weight_attendance' => 40,
            'score_weight_punctuality' => 25,
            'score_weight_working_hours' => 20,
            'score_weight_leave' => 15,
            'late_risk_threshold' => 3,
            'late_risk_period' => 'calendar_week',
            'late_risk_level' => 'HIGH RISK',
            'late_risk_recipients' => ['employee'],
            'ot_risk_weekly_minutes' => 720,
            'ot_risk_level' => 'NOTICE',
            'ot_risk_recipients' => ['employee'],
        ];

        // Ascending thresholds are rejected and nothing is saved.
        $this->actingAs($admin)
            ->from(route('scoring-settings.edit'))
            ->put(route('scoring-settings.update'), [
                ...$payload,
                'risk_bands' => [
                    ['min' => 60, 'label' => 'A'],
                    ['min' => 70, 'label' => 'B'],
                    ['min' => 80, 'label' => 'C'],
                    ['min' => 0, 'label' => 'D'],
                ],
            ])
            ->assertRedirect(route('scoring-settings.edit'))
            ->assertSessionHasErrors(['risk_bands.1.min', 'risk_bands.2.min']);

        $this->assertArrayNotHasKey('score_weight_attendance', $this->organization->fresh()->settings ?? []);

        // The catch-all band must reach 0.
        $this->actingAs($admin)
            ->from(route('scoring-settings.edit'))
            ->put(route('scoring-settings.update'), [
                ...$payload,
                'risk_bands' => [
                    ['min' => 90, 'label' => 'A'],
                    ['min' => 80, 'label' => 'B'],
                    ['min' => 70, 'label' => 'C'],
                    ['min' => 10, 'label' => 'D'],
                ],
            ])
            ->assertRedirect(route('scoring-settings.edit'))
            ->assertSessionHasErrors('risk_bands.3.min');
    }

    public function test_default_bands_match_the_spec_example(): void
    {
        $risk = app(RiskService::class);

        $this->assertSame('Excellent', $risk->statusFor($this->organization, 95.0)['label']);
        $this->assertSame('Good', $risk->statusFor($this->organization, 89.9)['label']);
        $this->assertSame('Needs Attention', $risk->statusFor($this->organization, 70.0)['label']);
        $this->assertSame('At Risk', $risk->statusFor($this->organization, 69.99)['label']);
    }

    public function test_score_engine_uses_configured_weights(): void
    {
        $admin = $this->makeUser($this->organization, 'company_admin', $this->roles);
        $employee = $this->employeeWithDefaults();

        // Attendance Rate carries the entire score.
        $this->actingAs($admin)
            ->put(route('scoring-settings.update'), [
                'score_weight_attendance' => 100,
                'score_weight_punctuality' => 0,
                'score_weight_working_hours' => 0,
                'score_weight_leave' => 0,
                'risk_bands' => [
                    ['min' => 90, 'label' => 'Excellent'],
                    ['min' => 80, 'label' => 'Good'],
                    ['min' => 70, 'label' => 'Needs Attention'],
                    ['min' => 0, 'label' => 'At Risk'],
                ],
                'late_risk_threshold' => 3,
                'late_risk_period' => 'calendar_week',
                'late_risk_level' => 'HIGH RISK',
                'late_risk_recipients' => ['employee'],
                'ot_risk_weekly_minutes' => 720,
                'ot_risk_level' => 'NOTICE',
                'ot_risk_recipients' => ['employee'],
            ])
            ->assertRedirect(route('scoring-settings.edit'));

        // One attended day out of 22 scheduled weekdays → 4.55 attendance.
        $this->seedDay($employee, '2026-09-01');

        $score = app(ScoreService::class)->compute($employee, '2026-09');

        $this->assertNotNull($score);
        $this->assertEqualsWithDelta(4.55, $score['scores']['attendance_rate'], 0.01);
        $this->assertEqualsWithDelta(4.55, $score['score'], 0.01);
    }

    private function employeeWithDefaults(): Employee
    {
        app(WorkforceDefaults::class)->apply($this->organization);
        $user = $this->makeUser($this->organization, 'employee', $this->roles);

        return $this->makeEmployee($this->organization, $user, 'EMP-RISK');
    }

    private function seedDay(Employee $employee, string $date): DailyAttendance
    {
        foreach ([[AttendanceEvent::CHECK_IN, '09:00'], [AttendanceEvent::CHECK_OUT, '18:00']] as [$type, $time]) {
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
}
