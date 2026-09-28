<?php

namespace Tests\Feature;

use App\Models\AttendanceEvent;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Services\AttendanceService;
use App\Services\WorkforceDefaults;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesOrganizations;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use CreatesOrganizations;
    use RefreshDatabase;

    public function test_admin_dashboard_reports_todays_attendance(): void
    {
        $this->travelTo(Carbon::parse('2026-09-30 10:00:00')); // Wednesday (workday)

        $ctx = $this->makeOrganization('DashCo');
        app(WorkforceDefaults::class)->apply($ctx['organization']);
        $admin = $this->makeUser($ctx['organization'], 'company_admin', $ctx['roles']);

        $userA = $this->makeUser($ctx['organization'], 'employee', $ctx['roles']);
        $employeeA = $this->makeEmployee($ctx['organization'], $userA, 'EMP-DA');
        $userB = $this->makeUser($ctx['organization'], 'employee', $ctx['roles']);
        $employeeB = $this->makeEmployee($ctx['organization'], $userB, 'EMP-DB');
        $userC = $this->makeUser($ctx['organization'], 'employee', $ctx['roles']);
        $employeeC = $this->makeEmployee($ctx['organization'], $userC, 'EMP-DC');

        $service = app(AttendanceService::class);
        $service->recordManual($employeeA, AttendanceEvent::CHECK_IN, now()->setTime(9, 5), 'seed', $admin->id);
        $service->deriveDaily($employeeB, now()->toDateString());

        $leaveType = $this->leaveType($ctx['organization']->id);
        $request = new LeaveRequest([
            'start_date' => now()->toDateString(),
            'end_date' => now()->toDateString(),
            'days' => 1,
            'reason' => 'Conference',
            'status' => LeaveRequest::APPROVED,
        ]);
        $request->organization()->associate($ctx['organization']);
        $request->employee()->associate($employeeC);
        $request->leaveType()->associate($leaveType);
        $request->save();

        $response = $this->actingAs($admin)->get(route('dashboard.admin'));
        $response->assertOk();

        $stats = $response->viewData('stats');
        $this->assertSame(1, $stats['present_today']);
        $this->assertSame(0, $stats['late_today']);
        $this->assertSame(1, $stats['absent_today']);
        $this->assertSame(1, $stats['on_leave_today']);
        $this->assertSame(3, $stats['total_employees']);

        $response->assertSee('Present today');
        $response->assertSee('On leave');
        $response->assertSee('Avg attendance');
    }

    public function test_admin_dashboard_counts_late_arrivals(): void
    {
        $this->travelTo(Carbon::parse('2026-09-30 10:00:00')); // Wednesday

        $ctx = $this->makeOrganization('DashLate');
        app(WorkforceDefaults::class)->apply($ctx['organization']);
        $admin = $this->makeUser($ctx['organization'], 'company_admin', $ctx['roles']);
        $userA = $this->makeUser($ctx['organization'], 'employee', $ctx['roles']);
        $employeeA = $this->makeEmployee($ctx['organization'], $userA, 'EMP-DL');

        // 09:30 with a 09:00 start and 15 minute grace → LATE.
        app(AttendanceService::class)
            ->recordManual($employeeA, AttendanceEvent::CHECK_IN, now()->setTime(9, 30), 'seed', $admin->id);

        $response = $this->actingAs($admin)->get(route('dashboard.admin'));
        $response->assertOk();

        $stats = $response->viewData('stats');
        $this->assertSame(1, $stats['present_today']);
        $this->assertSame(1, $stats['late_today']);
    }

    public function test_employee_dashboard_shows_calendar_and_month_stats(): void
    {
        $this->travelTo(Carbon::parse('2026-09-30 17:30:00')); // Wednesday

        $ctx = $this->makeOrganization('DashEmp');
        app(WorkforceDefaults::class)->apply($ctx['organization']);
        $user = $this->makeUser($ctx['organization'], 'employee', $ctx['roles']);
        $employee = $this->makeEmployee($ctx['organization'], $user, 'EMP-DE');

        $service = app(AttendanceService::class);
        $service->recordManual($employee, AttendanceEvent::CHECK_IN, now()->setTime(9, 5), 'seed', $user->id);
        $service->recordManual($employee, AttendanceEvent::CHECK_OUT, now()->setTime(18, 0), 'seed', $user->id);

        $response = $this->actingAs($user)->get(route('dashboard.employee'));
        $response->assertOk();

        $monthStats = $response->viewData('monthStats');
        $this->assertSame(1, $monthStats['present']);
        $this->assertSame(0, $monthStats['late']);
        // 09:05→18:00 = 535 segment minutes, minus the 60 minute break = 475.
        $this->assertSame(475, $monthStats['worked_minutes']);

        $response->assertSee('Worked this month');
        $response->assertSee('Recent attendance');
        $response->assertSee('Leave balance');
        $response->assertSee('Present');
        $response->assertSee('Needs review');
        $response->assertSee('EMP-DE');
    }

    public function test_employee_dashboard_handles_month_parameter(): void
    {
        $this->travelTo(Carbon::parse('2026-09-30 10:00:00'));

        $ctx = $this->makeOrganization('DashMon');
        app(WorkforceDefaults::class)->apply($ctx['organization']);
        $user = $this->makeUser($ctx['organization'], 'employee', $ctx['roles']);
        $this->makeEmployee($ctx['organization'], $user, 'EMP-DM');

        $this->actingAs($user)
            ->get(route('dashboard.employee').'?month=2026-08')
            ->assertOk()
            ->assertSee('August');

        $this->actingAs($user)
            ->get(route('dashboard.employee').'?month=bogus')
            ->assertOk();

        $this->actingAs($user)
            ->get(route('dashboard.employee').'?month=2026-13')
            ->assertOk();
    }

    private function leaveType(int $orgId): LeaveType
    {
        return LeaveType::withoutGlobalScopes()
            ->where('organization_id', $orgId)
            ->where('code', 'ANNUAL')
            ->firstOrFail();
    }
}
