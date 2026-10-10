<?php

namespace Tests\Feature;

use App\Models\AttendanceEvent;
use App\Models\DailyAttendance;
use App\Models\Employee;
use App\Models\Organization;
use App\Models\Role;
use App\Notifications\HighRiskAttendance;
use App\Services\AttendanceService;
use App\Services\WorkforceDefaults;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesOrganizations;
use Tests\TestCase;

/**
 * Phase 5 M5d — §39/§15 wiring: the Statistics RISK & NOTIFICATIONS
 * section, the employee dashboard high-risk banner, the notification
 * center entry and the admin dashboard score/risk/overtime widgets (§18).
 */
class RiskWiringTest extends TestCase
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

        $ctx = $this->makeOrganization('Wiring '.uniqid());
        $this->organization = $ctx['organization'];
        $this->roles = $ctx['roles'];
        app(WorkforceDefaults::class)->apply($this->organization);
    }

    public function test_statistics_page_shows_risk_status_counts_and_alerts(): void
    {
        $employee = $this->employeeWithThreeLateDays();

        $this->actingAs($employee->user)
            ->get(route('statistics.index', ['month' => '2026-09']))
            ->assertOk()
            ->assertSee('Risk & Notifications') // assertSee escapes the ampersand
            ->assertSee('Current Risk Status')
            ->assertSee('Excellent')          // score 96.42 → top band
            ->assertSee('96.42')
            ->assertSee('Late Days This Week')
            ->assertSee('Configured Late Threshold')
            ->assertSee('HIGH RISK')          // triggered alert
            ->assertSee('High-Risk Alert')
            ->assertSee('Late arrivals this week: 3');
    }

    public function test_employee_dashboard_surfaces_the_high_risk_banner(): void
    {
        $employee = $this->employeeWithThreeLateDays();

        $this->actingAs($employee->user)
            ->get(route('dashboard.employee'))
            ->assertOk()
            ->assertSee('HIGH RISK')
            ->assertSee('Late arrivals this week: 3')
            ->assertSee('reached the configured late-arrival');
    }

    public function test_notification_center_lists_the_high_risk_alert(): void
    {
        $employee = $this->employeeWithThreeLateDays();

        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $employee->user->id,
            'type' => HighRiskAttendance::class,
        ]);

        $this->actingAs($employee->user)
            ->get(route('notifications.index'))
            ->assertOk()
            ->assertSee('HIGH RISK');
    }

    public function test_admin_dashboard_shows_score_risk_and_overtime_widgets(): void
    {
        $employee = $this->employeeWithThreeLateDays();
        $admin = $this->makeUser($this->organization, 'company_admin', $this->roles);

        $this->actingAs($admin)
            ->get(route('dashboard.admin'))
            ->assertOk()
            ->assertSee('Average Score')
            ->assertSee('96.4')               // average over the one employee
            ->assertSee('At Risk Employees')
            ->assertSee('High-Risk Alerts')
            ->assertSee('Overtime Today')
            ->assertSee('Pending Overtime Approvals')
            ->assertSee('Overtime Hours This Month');
    }

    /** All 22 September weekdays, late (09:30) on Mon 28 – Wed 30. */
    private function employeeWithThreeLateDays(): Employee
    {
        $user = $this->makeUser($this->organization, 'employee', $this->roles);
        $employee = $this->makeEmployee($this->organization, $user, 'EMP-WIRE');

        $lateDays = ['2026-09-28', '2026-09-29', '2026-09-30'];
        $cursor = Carbon::parse('2026-09-01');

        while ($cursor->month === 9) {
            if (in_array((int) $cursor->dayOfWeekIso, [1, 2, 3, 4, 5], true)) {
                $date = $cursor->toDateString();
                $this->seedDay($employee, $date, in_array($date, $lateDays, true) ? '09:30' : '09:00');
            }
            $cursor->addDay();
        }

        return $employee;
    }

    private function seedDay(Employee $employee, string $date, string $in): DailyAttendance
    {
        foreach ([[AttendanceEvent::CHECK_IN, $in], [AttendanceEvent::CHECK_OUT, '18:00']] as [$type, $time]) {
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
