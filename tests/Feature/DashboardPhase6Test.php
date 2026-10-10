<?php

namespace Tests\Feature;

use App\Models\AttendanceEvent;
use App\Models\DailyAttendance;
use App\Models\Employee;
use App\Models\Organization;
use App\Models\OvertimeRecord;
use App\Models\Role;
use App\Models\User;
use App\Services\AttendanceService;
use App\Services\WorkforceDefaults;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesOrganizations;
use Tests\TestCase;

/**
 * Phase 6 M6f — §39 employee dashboard: the main attendance-score card,
 * working hours vs expected, the [OT] overtime card, calendar overtime
 * dots/tooltips, the recent-attendance overtime line and the weekly
 * summary below the calendar.
 */
class DashboardPhase6Test extends TestCase
{
    use CreatesOrganizations;
    use RefreshDatabase;

    private Organization $organization;

    /** @var array<string, Role> */
    private array $roles;

    private User $admin;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-09-30 23:00:00');

        $ctx = $this->makeOrganization('Dash6 '.uniqid());
        $this->organization = $ctx['organization'];
        $this->roles = $ctx['roles'];
        app(WorkforceDefaults::class)->apply($this->organization);

        $this->admin = $this->makeUser($this->organization, 'company_admin', $this->roles);
        $this->employee = $this->makeEmployee(
            $this->organization,
            $this->makeUser($this->organization, 'employee', $this->roles),
            'EMP-D6',
        );
    }

    public function test_score_card_overtime_card_and_weekly_summary_render(): void
    {
        $this->seedFullMonth($this->employee); // score 96.42 → band "Excellent"

        $this->record($this->employee, '2026-09-14', 'WORKING_DAY', 'APPROVED', 120, 1.5, 300);
        $this->record($this->employee, '2026-09-16', 'WORKING_DAY', 'PENDING', 60, 1.5, 75);
        $this->record($this->employee, '2026-09-30', 'WORKING_DAY', 'PENDING', 75, 1.5, 50);

        $this->actingAs($this->employee->user)
            ->get(route('dashboard.employee'))
            ->assertOk()
            // Main score card (§39).
            ->assertSee('Attendance Score')
            ->assertSee('Excellent')
            ->assertSee('leading-none">96</div>', false)
            // Working hours never include overtime: worked vs expected.
            ->assertSee('of 176h 0m expected')
            // [OT] overtime card.
            ->assertSee('Overtime / Extra Time')
            ->assertSee('This Month')
            ->assertSee('4h 15m')  // approved 120 + pending 135
            ->assertSee('2h 0m')   // approved
            ->assertSee('2h 15m')  // pending 60 + 75
            ->assertSee('Today:')
            ->assertSee('1h 15m')  // today's 75 minutes
            ->assertSee('Est. Overtime Pay')
            ->assertSee('300.00')
            ->assertSee('Statistics')
            // Calendar overtime dot + day tooltip.
            ->assertSee('Overtime pending')
            ->assertSee('overtime (pending)')
            // Recent attendance overtime line.
            ->assertSee('+1h 15m OT (pending)')
            // Weekly summary below the calendar.
            ->assertSee('This Week')
            ->assertSee('(Approved 0h 0m, Pending 1h 15m)')
            ->assertSee('Overtime Pay');
    }

    public function test_not_eligible_employee_gets_no_overtime_card_or_overlays(): void
    {
        $this->employee->overtime_eligibility = Employee::OT_NOT_ELIGIBLE;
        $this->employee->save();

        $this->record($this->employee, '2026-09-16', 'WORKING_DAY', 'PENDING', 60, 1.5, 75);
        $this->seedDay($this->employee, '2026-09-16', '09:00');

        $this->actingAs($this->employee->user)
            ->get(route('dashboard.employee'))
            ->assertOk()
            ->assertSee('This Week')
            ->assertDontSee('Overtime / Extra Time')
            ->assertDontSee('data-test="overtime-card"')
            ->assertDontSee('Overtime pending')
            ->assertDontSee('overtime (pending)')
            ->assertDontSee('OT (pending)')
            ->assertDontSee('Overtime (approved)');
    }

    public function test_weekly_summary_shows_late_deduction_and_week_overtime(): void
    {
        $this->actingAs($this->admin)
            ->post(route('salary.store', $this->employee), [
                'basic_salary' => 30000,
                'allowances' => 2000,
                'effective_from' => '2026-06-01',
            ])
            ->assertRedirect(route('salary.show', $this->employee));

        // Current week (Mon 28 Sep – Sun 4 Oct): one late day with overtime.
        $this->seedDay($this->employee, '2026-09-29', '09:45');
        $this->record($this->employee, '2026-09-29', 'WORKING_DAY', 'APPROVED', 60, 1.5, 100);

        $this->actingAs($this->employee->user)
            ->get(route('dashboard.employee'))
            ->assertOk()
            ->assertSee('This Week')
            ->assertSee('Late Salary Deduction')
            ->assertSee('(Approved 1h 0m, Pending 0h 0m)')
            ->assertSee('100.00');
    }

    /**
     * All 22 September weekdays, late (09:30) on Mon 28 – Wed 30 →
     * score 96.42 / Excellent (same fixture as RiskWiringTest).
     */
    private function seedFullMonth(Employee $employee): void
    {
        $lateDays = ['2026-09-28', '2026-09-29', '2026-09-30'];
        $cursor = Carbon::parse('2026-09-01');

        while ($cursor->month === 9) {
            if (in_array((int) $cursor->dayOfWeekIso, [1, 2, 3, 4, 5], true)) {
                $this->seedDay($employee, $cursor->toDateString(), in_array($cursor->toDateString(), $lateDays, true) ? '09:30' : '09:00');
            }
            $cursor->addDay();
        }
    }

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
