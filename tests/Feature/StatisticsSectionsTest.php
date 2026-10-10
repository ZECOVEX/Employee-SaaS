<?php

namespace Tests\Feature;

use App\Models\AttendanceEvent;
use App\Models\DailyAttendance;
use App\Models\Employee;
use App\Models\Organization;
use App\Models\OvertimeRecord;
use App\Models\Role;
use App\Services\AttendanceService;
use App\Services\WorkforceDefaults;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesOrganizations;
use Tests\TestCase;

/**
 * Phase 6 M6a/M6b — §39 Statistics sections: monthly overview, weekly
 * attendance with the week filter, and the overtime / extra-time section
 * with status splits, earnings and configured limit usage.
 */
class StatisticsSectionsTest extends TestCase
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

        $ctx = $this->makeOrganization('Stats '.uniqid());
        $this->organization = $ctx['organization'];
        $this->roles = $ctx['roles'];
        app(WorkforceDefaults::class)->apply($this->organization);
    }

    public function test_monthly_overview_and_weekly_attendance_sections_render(): void
    {
        $employee = $this->makeEmployee(
            $this->organization,
            $this->makeUser($this->organization, 'employee', $this->roles),
            'EMP-OVW',
        );

        foreach (['2026-09-07', '2026-09-08', '2026-09-09'] as $date) {
            $this->seedDay($employee, $date);
        }

        $this->actingAs($employee->user)
            ->get(route('statistics.index'))
            ->assertOk()
            ->assertSee('Monthly overview')
            ->assertSee('Expected / worked hours')
            ->assertSee('Avg arrival / departure')
            ->assertSee('09:00')
            ->assertSee('Remaining leave')
            ->assertSee('Weekly attendance')
            ->assertSee('Week of Mon 28 Sep 2026 – Sun 4 Oct 2026')
            ->assertSee('Prev week')
            ->assertSee('Worked / expected')
            ->assertSee('Late minutes / deduction');
    }

    public function test_week_filter_scopes_the_weekly_section(): void
    {
        $employee = $this->makeEmployee(
            $this->organization,
            $this->makeUser($this->organization, 'employee', $this->roles),
            'EMP-WKF',
        );

        $this->actingAs($employee->user)
            ->get(route('statistics.index', ['week' => '2026-09-21']))
            ->assertOk()
            ->assertSee('Week of Mon 21 Sep 2026 – Sun 27 Sep 2026');

        $this->actingAs($employee->user)
            ->get(route('statistics.index', ['week' => '2026-09-07']))
            ->assertOk()
            ->assertSee('Week of Mon 7 Sep 2026 – Sun 13 Sep 2026');
    }

    public function test_overtime_section_shows_splits_earnings_and_limit_usage(): void
    {
        $this->organization->update([
            'settings' => array_merge($this->organization->settings ?? [], [
                'ot_risk_monthly_minutes' => 180,
            ]),
        ]);

        $employee = $this->makeEmployee(
            $this->organization,
            $this->makeUser($this->organization, 'employee', $this->roles),
            'EMP-OTSEC',
        );

        $this->record($employee, '2026-09-14', 'WORKING_DAY', 'APPROVED', 120, 1.5, 300);
        $this->record($employee, '2026-09-16', 'WORKING_DAY', 'PENDING', 60, 1.5, 75);
        $this->record($employee, '2026-09-18', 'WORKING_DAY', 'REJECTED', 30, 1.5, 0);
        $this->record($employee, '2026-09-26', 'WEEKEND', 'APPROVED', 60, 2.0, 120);
        $this->record($employee, '2026-09-29', 'WORKING_DAY', 'AUTO_APPROVED', 45, 1.5, 90);

        $this->actingAs($employee->user)
            ->get(route('statistics.index'))
            ->assertOk()
            ->assertSee('Overtime / extra time')
            ->assertSee('Approved (incl. auto)')
            ->assertSee('3h 45m')          // 120 + 60 + 45
            ->assertSee('1h 00m')          // pending 60
            ->assertSee('0h 30m')          // rejected 30
            ->assertSee('Overtime days')
            ->assertSee('4h 15m')          // weekday 120+60+30+45
            ->assertSee('1.5×, 2×')        // applied multipliers
            ->assertSee('Overtime earnings (approved)')
            ->assertSee('510.00')          // 300 + 120 + 90
            ->assertSee('Potential (pending')
            ->assertSee('75.00')
            ->assertSee('Overtime limit usage')
            ->assertSee('0h 45m')          // usage inside the current week
            ->assertSee('of 12h 00m')      // default weekly limit (720 min)
            ->assertSee('of 3h 00m')       // configured monthly limit (180 min)
            ->assertSee('over limit')      // 4h 45m > 3h
            ->assertSee('Automatic')       // auto-approved row has no approver
            ->assertSee('Sat');            // weekend record date shown
    }

    public function test_not_eligible_employees_get_a_muted_note_instead_of_numbers(): void
    {
        $employee = $this->makeEmployee(
            $this->organization,
            $this->makeUser($this->organization, 'employee', $this->roles),
            'EMP-NOOT',
        );
        $employee->overtime_eligibility = Employee::OT_NOT_ELIGIBLE;
        $employee->save();

        $this->record($employee, '2026-09-14', 'WORKING_DAY', 'APPROVED', 120, 1.5, 300);

        $this->actingAs($employee->user)
            ->get(route('statistics.index'))
            ->assertOk()
            ->assertSee('not overtime-eligible')
            ->assertDontSee('Overtime limit usage')
            ->assertDontSee('Overtime earnings (approved)')
            ->assertDontSee('3h 45m');
    }

    /**
     * @return array{organization_id: int, employee_id: int, work_date: string, day_type: string, worked_minutes: int, expected_minutes: int, raw_minutes: int, countable_minutes: int, multiplier: float, estimated_pay: float, status: string, calculated_at: Carbon}
     */
    private function record(
        Employee $employee,
        string $date,
        string $dayType,
        string $status,
        int $countable,
        float $multiplier,
        float $pay,
    ): OvertimeRecord {
        return OvertimeRecord::withoutGlobalScopes()->create([
            'organization_id' => $employee->organization_id,
            'employee_id' => $employee->id,
            'work_date' => $date,
            'day_type' => $dayType,
            'worked_minutes' => 480 + $countable,
            'expected_minutes' => 480,
            'raw_minutes' => $countable,
            'countable_minutes' => $countable,
            'multiplier' => $multiplier,
            'estimated_pay' => $pay,
            'status' => $status,
            'calculated_at' => now(),
        ]);
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
