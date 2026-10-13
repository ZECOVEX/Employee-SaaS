<?php

namespace Tests\Feature;

use App\Models\AttendanceEvent;
use App\Models\Employee;
use App\Models\EmployeeMonthlyStatus;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Organization;
use App\Models\RiskEvent;
use App\Models\Role;
use App\Models\User;
use App\Services\AttendanceService;
use App\Services\WorkforceDefaults;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesOrganizations;
use Tests\TestCase;

/**
 * Analytics dashboard enhancements: workforce mix, leave analytics,
 * status distribution, workload, score bands and risk overview.
 */
class AnalyticsEnhancedTest extends TestCase
{
    use CreatesOrganizations;
    use RefreshDatabase;

    /**
     * Org + defaults + admin + one active employee.
     *
     * @return array{0: Organization, 1: User, 2: Employee, 3: array<string, Role>}
     */
    private function bootAnalytics(string $name): array
    {
        $ctx = $this->makeOrganization($name);
        app(WorkforceDefaults::class)->apply($ctx['organization']);

        $admin = $this->makeUser($ctx['organization'], 'company_admin', $ctx['roles']);
        $employee = $this->makeEmployee(
            $ctx['organization'],
            $this->makeUser($ctx['organization'], 'employee', $ctx['roles']),
            'EMP-A1',
        );

        return [$ctx['organization'], $admin, $employee, $ctx['roles']];
    }

    private function leave(Organization $org, Employee $employee, string $code, string $status, string $start, string $end): LeaveRequest
    {
        $type = LeaveType::withoutGlobalScopes()
            ->where('organization_id', $org->id)
            ->where('code', $code)
            ->firstOrFail();

        $request = new LeaveRequest([
            'start_date' => $start,
            'end_date' => $end,
            'days' => Carbon::parse($start)->diffInDays(Carbon::parse($end)) + 1,
            'reason' => 'Trip',
            'status' => $status,
        ]);
        $request->organization()->associate($org);
        $request->employee()->associate($employee);
        $request->leaveType()->associate($type);
        $request->save();

        return $request;
    }

    private function monthlyStatus(Organization $org, Employee $employee, int $year, int $month, float $score): EmployeeMonthlyStatus
    {
        return EmployeeMonthlyStatus::withoutGlobalScopes()->create([
            'organization_id' => $org->id,
            'employee_id' => $employee->id,
            'year' => $year,
            'month' => $month,
            'attendance_score' => $score,
            'attendance_rate' => $score,
            'punctuality_score' => $score,
            'working_hours_score' => $score,
            'leave_score' => $score,
            'days_present' => 20,
            'days_absent' => 0,
            'days_late' => 0,
            'days_on_leave' => 0,
            'total_work_minutes' => 9600,
            'expected_work_minutes' => 9600,
            'overtime_minutes_approved' => 0,
            'overtime_minutes_pending' => 0,
            'overtime_minutes_rejected' => 0,
            'overtime_days' => 0,
            'status' => EmployeeMonthlyStatus::STATUS_FINALIZED,
            'generated_at' => now(),
        ]);
    }

    public function test_analytics_renders_the_new_detail_sections_with_empty_states(): void
    {
        $this->travelTo(Carbon::parse('2026-10-05 12:00:00'));
        [, $admin] = $this->bootAnalytics('AnaNew');

        $this->actingAs($admin)->get(route('analytics.index'))
            ->assertOk()
            ->assertSee('Workforce mix')
            ->assertSee('Attendance status distribution')
            ->assertSee('Leave requests')
            ->assertSee('Leave by type')
            ->assertSee('Performance scores')
            ->assertSee('Risk overview')
            ->assertSee('Avg attendance score')
            ->assertSee('Approved leave (month)')
            ->assertSee('Pending leave requests')
            ->assertSee('Worked vs expected')
            ->assertSee('No attendance rows this month yet.')
            ->assertSee('No approved leave this year.')
            ->assertSee('No open risk alerts.')
            ->assertSee('none yet');
    }

    public function test_analytics_renders_leave_scores_and_risk_aggregates(): void
    {
        $this->travelTo(Carbon::parse('2026-10-05 12:00:00'));
        [$org, $admin, $employee, $roles] = $this->bootAnalytics('AnaAgg');

        // October leave: one approved (2 days) + one pending (1 day).
        $this->leave($org, $employee, 'ANNUAL', LeaveRequest::APPROVED, '2026-10-01', '2026-10-02');
        $this->leave($org, $employee, 'SICK', LeaveRequest::PENDING, '2026-10-07', '2026-10-07');

        // Two finalized snapshots (95 + 70) → avg 82.5% for Sep 2026.
        $this->monthlyStatus($org, $employee, 2026, 9, 95.0);
        $employeeB = $this->makeEmployee($org, $this->makeUser($org, 'employee', $roles), 'EMP-A2');
        $this->monthlyStatus($org, $employeeB, 2026, 9, 70.0);

        // Open risk window covering today.
        RiskEvent::withoutGlobalScopes()->create([
            'organization_id' => $org->id,
            'employee_id' => $employee->id,
            'type' => RiskEvent::TYPE_LATE_WEEK,
            'level' => 'HIGH RISK',
            'period_start' => '2026-09-29',
            'period_end' => '2026-10-05',
            'observed' => 4,
            'threshold' => 3,
            'detail' => '4 late days this week',
            'notified_at' => now(),
        ]);

        // One attendance day so the status distribution has data.
        $service = app(AttendanceService::class);
        $service->recordManual($employee, AttendanceEvent::CHECK_IN, Carbon::parse('2026-10-01 09:00'), 'seed', $employee->user_id);
        $service->recordManual($employee, AttendanceEvent::CHECK_OUT, Carbon::parse('2026-10-01 18:00'), 'seed', $employee->user_id);

        $this->actingAs($admin)->get(route('analytics.index'))
            ->assertOk()
            ->assertSee('82.5%')
            ->assertSee('Sep 2026')
            ->assertSee('Excellent (90+)')
            ->assertSee('Average (60-74)')
            ->assertSee('Annual Leave')
            ->assertSee('1 request awaiting review')
            ->assertSee('Weekly late risk')
            ->assertSee('HIGH RISK · 1')
            ->assertSee('Present');
    }

    public function test_analytics_new_sections_are_tenant_isolated(): void
    {
        $this->travelTo(Carbon::parse('2026-10-05 12:00:00'));
        [$orgA, $adminA, $employeeA] = $this->bootAnalytics('AnaIsoA');

        // Org-A-only leave type + an open risk window.
        $type = LeaveType::withoutGlobalScopes()->create([
            'organization_id' => $orgA->id,
            'code' => 'SABB',
            'name' => 'Sabbatical Trial Leave',
            'days' => 15,
            'paid' => true,
        ]);
        $request = new LeaveRequest([
            'start_date' => '2026-10-01',
            'end_date' => '2026-10-01',
            'days' => 1,
            'reason' => 'Trip',
            'status' => LeaveRequest::APPROVED,
        ]);
        $request->organization()->associate($orgA);
        $request->employee()->associate($employeeA);
        $request->leaveType()->associate($type);
        $request->save();

        RiskEvent::withoutGlobalScopes()->create([
            'organization_id' => $orgA->id,
            'employee_id' => $employeeA->id,
            'type' => RiskEvent::TYPE_OT_WEEK,
            'level' => 'NOTICE',
            'period_start' => '2026-09-29',
            'period_end' => '2026-10-05',
            'observed' => 900,
            'threshold' => 720,
            'detail' => '900 overtime minutes this week',
            'notified_at' => now(),
        ]);

        $ctxB = $this->makeOrganization('AnaIsoB');
        $adminB = $this->makeUser($ctxB['organization'], 'company_admin', $ctxB['roles']);

        $this->actingAs($adminB)->get(route('analytics.index'))
            ->assertOk()
            ->assertDontSee('Sabbatical Trial Leave')
            ->assertDontSee('Weekly overtime limit')
            ->assertSee('No approved leave this year.')
            ->assertSee('No open risk alerts.');

        $this->actingAs($adminA)->get(route('analytics.index'))
            ->assertOk()
            ->assertSee('Sabbatical Trial Leave')
            ->assertSee('Weekly overtime limit');
    }
}
