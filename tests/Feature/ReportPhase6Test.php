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
 * Phase 6 M6c/M6d/M6e — the §41 overtime report (filters, status split,
 * salary-gated pay column, CSV), the completed monthly report columns
 * (score, status band, approved/pending overtime) and the analytics
 * overtime trend chart.
 */
class ReportPhase6Test extends TestCase
{
    use CreatesOrganizations;
    use RefreshDatabase;

    private Organization $organization;

    /** @var array<string, Role> */
    private array $roles;

    private User $admin;

    private User $manager;

    private User $user;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-09-30 23:00:00');

        $ctx = $this->makeOrganization('Phase6 '.uniqid());
        $this->organization = $ctx['organization'];
        $this->roles = $ctx['roles'];
        app(WorkforceDefaults::class)->apply($this->organization);

        $this->admin = $this->makeUser($this->organization, 'company_admin', $this->roles);
        $this->manager = $this->makeUser($this->organization, 'manager', $this->roles);
        $this->user = $this->makeUser($this->organization, 'employee', $this->roles, [
            'name' => 'Ot Reporter',
        ]);
        $this->employee = $this->makeEmployee($this->organization, $this->user, 'EMP-P6');
    }

    public function test_overtime_report_renders_rows_and_filters(): void
    {
        $this->record($this->employee, '2026-09-14', 'WORKING_DAY', 'APPROVED', 120, 1.5, 300);
        $this->record($this->employee, '2026-09-16', 'WORKING_DAY', 'PENDING', 60, 1.5, 75);
        $this->record($this->employee, '2026-09-18', 'WORKING_DAY', 'AUTO_APPROVED', 45, 1.5, 90);
        $this->record($this->employee, '2026-09-26', 'WEEKEND', 'APPROVED', 60, 2.0, 120);
        $this->record($this->employee, '2026-09-20', 'HOLIDAY', 'REJECTED', 30, 1.5, 0);

        // Another organization's record must never leak into the rows.
        $other = $this->makeOrganization('Other '.uniqid());
        $otherEmployee = $this->makeEmployee(
            $other['organization'],
            $this->makeUser($other['organization'], 'employee', $other['roles']),
            'EMP-OTH',
        );
        $this->record($otherEmployee, '2026-09-14', 'WORKING_DAY', 'APPROVED', 999, 1.5, 100);

        $this->actingAs($this->admin)
            ->get(route('reports.overtime'))
            ->assertOk()
            ->assertSee('Rows: 5')
            ->assertSee('Raw minutes')
            ->assertSee('Approved minutes')
            ->assertSee('Estimated pay')
            ->assertSee('Ot Reporter')
            ->assertSee('WEEKEND')
            ->assertSee('HOLIDAY')
            ->assertSee('1.5×')
            ->assertSee('2×')
            // Tenant isolation: the other org's employee (and its 999
            // minutes) must never appear. Match on the employee code — a
            // raw digits check would flake against random CSRF tokens.
            ->assertDontSee('EMP-OTH');

        // "Approved" covers manual and automatic approvals (§41).
        $this->actingAs($this->admin)
            ->get(route('reports.overtime', ['status' => 'APPROVED']))
            ->assertOk()
            ->assertSee('Rows: 3');

        $this->actingAs($this->admin)
            ->get(route('reports.overtime', ['status' => 'REJECTED']))
            ->assertOk()
            ->assertSee('Rows: 1');

        $this->actingAs($this->admin)
            ->get(route('reports.overtime', ['day_type' => 'WORKING_DAY']))
            ->assertOk()
            ->assertSee('Rows: 3');

        $this->actingAs($this->admin)
            ->get(route('reports.overtime', ['day_type' => 'WEEKEND']))
            ->assertOk()
            ->assertSee('Rows: 1');

        $this->actingAs($this->admin)
            ->get(route('reports.overtime', ['from' => '2026-09-14', 'to' => '2026-09-16']))
            ->assertOk()
            ->assertSee('Rows: 2');

        $emptyEmployee = $this->makeEmployee(
            $this->organization,
            $this->makeUser($this->organization, 'employee', $this->roles),
            'EMP-EMPTY',
        );

        $this->actingAs($this->admin)
            ->get(route('reports.overtime', ['employee_id' => $emptyEmployee->id]))
            ->assertOk()
            ->assertSee('Rows: 0')
            ->assertSee('No overtime rows match these filters.');
    }

    public function test_overtime_report_validation(): void
    {
        $this->actingAs($this->admin)
            ->from(route('reports.overtime'))
            ->get(route('reports.overtime', ['day_type' => 'BOGUS']))
            ->assertSessionHasErrors('day_type');

        $this->actingAs($this->admin)
            ->from(route('reports.overtime'))
            ->get(route('reports.overtime', ['from' => '2026-09-30', 'to' => '2026-09-01']))
            ->assertSessionHasErrors('to');

        $this->actingAs($this->admin)
            ->from(route('reports.overtime'))
            ->get(route('reports.overtime', ['status' => 'MAYBE']))
            ->assertSessionHasErrors('status');
    }

    public function test_overtime_report_pay_column_requires_salary_view(): void
    {
        $this->record($this->employee, '2026-09-14', 'WORKING_DAY', 'APPROVED', 120, 1.5, 300);

        // Admin holds salary.view → pay column visible on screen and in CSV.
        $this->actingAs($this->admin)
            ->get(route('reports.overtime'))
            ->assertOk()
            ->assertSee('Estimated pay')
            ->assertSee('300.00')
            ->assertDontSee('Pay columns are hidden');

        $csv = $this->actingAs($this->admin)
            ->get(route('reports.export', ['type' => 'overtime']))
            ->assertOk()
            ->assertDownload('overtime_report_2026-09-01_2026-09-30.csv')
            ->streamedContent();

        $lines = array_values(array_filter(explode("\n", trim($csv))));
        $this->assertStringContainsString('Estimated pay', $lines[0]);
        $this->assertStringContainsString('2026-09-14', $lines[1]);
        $this->assertStringContainsString(',300', $lines[1]);

        // Manager has reports.view but NOT salary.view → pay stays hidden.
        $this->actingAs($this->manager)
            ->get(route('reports.overtime'))
            ->assertOk()
            ->assertSee('Pay columns are hidden')
            ->assertDontSee('Estimated pay')
            ->assertDontSee('300.00');

        $managerCsv = $this->actingAs($this->manager)
            ->get(route('reports.export', ['type' => 'overtime']))
            ->assertOk()
            ->streamedContent();

        $managerLines = array_values(array_filter(explode("\n", trim($managerCsv))));
        $this->assertStringContainsString('Approver', $managerLines[0]);
        $this->assertStringNotContainsString('Estimated pay', $managerLines[0]);
        $this->assertStringNotContainsString('300', $managerLines[1]);
    }

    public function test_monthly_report_shows_score_status_and_overtime_split(): void
    {
        // Full-attendance September with three late days → score 96.42
        // (the same verified fixture as RiskWiringTest).
        $lateDays = ['2026-09-28', '2026-09-29', '2026-09-30'];
        $cursor = Carbon::parse('2026-09-01');

        while ($cursor->month === 9) {
            if (in_array((int) $cursor->dayOfWeekIso, [1, 2, 3, 4, 5], true)) {
                $this->seedDay($this->employee, $cursor->toDateString(), in_array($cursor->toDateString(), $lateDays, true) ? '09:30' : '09:00');
            }
            $cursor->addDay();
        }

        $this->record($this->employee, '2026-09-14', 'WORKING_DAY', 'APPROVED', 120, 1.5, 300);
        $this->record($this->employee, '2026-09-16', 'WORKING_DAY', 'PENDING', 60, 1.5, 75);

        $this->actingAs($this->admin)
            ->get(route('reports.monthly'))
            ->assertOk()
            ->assertSee('Overtime (approved / pending)')
            ->assertSee('Score')
            ->assertSee('Status')
            ->assertSee('96.42')
            ->assertSee('Excellent')
            ->assertSee('2 / 1') // approved 120m / pending 60m
            ->assertSee('approved / pending hours [OT]')
            ->assertDontSee('columns are reserved');

        $csv = $this->actingAs($this->admin)
            ->get(route('reports.export', ['type' => 'monthly', 'month' => '2026-09']))
            ->assertOk()
            ->assertDownload('monthly_report_2026-09.csv')
            ->streamedContent();

        $lines = array_values(array_filter(explode("\n", trim($csv))));
        $this->assertStringContainsString('Overtime approved hours', $lines[0]);
        $this->assertStringContainsString('Overtime pending hours', $lines[0]);
        $this->assertStringContainsString('Score', $lines[0]);
        $this->assertStringContainsString('Status', $lines[0]);
        $this->assertStringContainsString('96.42', $lines[1]);
        $this->assertStringContainsString('Excellent', $lines[1]);
    }

    public function test_analytics_renders_the_overtime_trend_chart(): void
    {
        $this->record($this->employee, '2026-09-14', 'WORKING_DAY', 'APPROVED', 120, 1.5, 300);
        $this->record($this->employee, '2026-09-16', 'WORKING_DAY', 'PENDING', 60, 1.5, 75);

        $this->actingAs($this->admin)
            ->get(route('analytics.index'))
            ->assertOk()
            ->assertSee('Overtime trend')
            ->assertSee('approved / pending hours')
            ->assertSee('2h approved / 1h pending') // current-month bar tooltip
            ->assertSee('Approved')
            ->assertSee('Pending');
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
