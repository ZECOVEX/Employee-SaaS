<?php

namespace Tests\Feature;

use App\Models\AttendanceEvent;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Services\AttendanceService;
use App\Services\WorkforceDefaults;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesOrganizations;
use Tests\TestCase;

/**
 * Post-Phase 19 — hover drill-downs: every dashboard stat card can expose the
 * underlying rows (names, arrival times, leave types, per-day lists).
 */
class DashboardDrilldownTest extends TestCase
{
    use CreatesOrganizations;
    use RefreshDatabase;

    public function test_admin_present_drilldown_lists_people_and_arrival_times(): void
    {
        $this->travelTo(Carbon::parse('2026-09-30 10:00:00')); // Wednesday (workday)

        $ctx = $this->makeOrganization('DrillAdm');
        app(WorkforceDefaults::class)->apply($ctx['organization']);
        $admin = $this->makeUser($ctx['organization'], 'company_admin', $ctx['roles']);
        $userA = $this->makeUser($ctx['organization'], 'employee', $ctx['roles']);
        $employeeA = $this->makeEmployee($ctx['organization'], $userA, 'EMP-DR');

        app(AttendanceService::class)
            ->recordManual($employeeA, AttendanceEvent::CHECK_IN, now()->setTime(9, 3), 'seed', $admin->id);

        $response = $this->actingAs($admin)->get(route('dashboard.admin'));
        $response->assertOk();

        $drilldowns = $response->viewData('drilldowns');

        $first = collect($drilldowns['present_today']['rows'])->first();
        $this->assertSame($userA->name, $first['name']);
        $this->assertSame('Arrived 09:03', $first['detail']);

        // The row is rendered server-side (hidden until hover) → visible in HTML.
        $response->assertSee('Arrived 09:03');
    }

    public function test_admin_leave_drilldown_shows_type_and_employee(): void
    {
        $this->travelTo(Carbon::parse('2026-09-30 10:00:00'));

        $ctx = $this->makeOrganization('DrillLv');
        app(WorkforceDefaults::class)->apply($ctx['organization']);
        $admin = $this->makeUser($ctx['organization'], 'company_admin', $ctx['roles']);
        $userA = $this->makeUser($ctx['organization'], 'employee', $ctx['roles']);
        $employeeA = $this->makeEmployee($ctx['organization'], $userA, 'EMP-DV');

        $leaveType = $this->leaveType($ctx['organization']->id);
        $request = new LeaveRequest([
            'start_date' => now()->toDateString(),
            'end_date' => now()->toDateString(),
            'days' => 1,
            'reason' => 'Conference',
            'status' => LeaveRequest::APPROVED,
        ]);
        $request->organization()->associate($ctx['organization']);
        $request->employee()->associate($employeeA);
        $request->leaveType()->associate($leaveType);
        $request->save();

        $response = $this->actingAs($admin)->get(route('dashboard.admin'));
        $response->assertOk();

        $drilldowns = $response->viewData('drilldowns');

        $row = collect($drilldowns['on_leave_today']['rows'])->first();
        $this->assertSame($userA->name, $row['name']);
        $this->assertStringContainsString($leaveType->name, $row['detail']);
    }

    public function test_employee_month_tiles_expose_per_day_drilldowns(): void
    {
        $this->travelTo(Carbon::parse('2026-09-30 17:30:00')); // Wednesday

        $ctx = $this->makeOrganization('DrillEmp');
        app(WorkforceDefaults::class)->apply($ctx['organization']);
        $user = $this->makeUser($ctx['organization'], 'employee', $ctx['roles']);
        $employee = $this->makeEmployee($ctx['organization'], $user, 'EMP-DW');

        $service = app(AttendanceService::class);
        $service->recordManual($employee, AttendanceEvent::CHECK_IN, now()->setTime(9, 5), 'seed', $user->id);
        $service->recordManual($employee, AttendanceEvent::CHECK_OUT, now()->setTime(18, 0), 'seed', $user->id);

        $response = $this->actingAs($user)->get(route('dashboard.employee'));
        $response->assertOk();

        $drilldowns = $response->viewData('drilldowns');

        $present = collect($drilldowns['present']['rows'])->first();
        $this->assertSame('Wed, 30 Sep', $present['name']);
        $this->assertSame('Arrived 09:05', $present['detail']);
        $this->assertSame([], $drilldowns['late']['rows']);
        $this->assertSame([], $drilldowns['absent']['rows']);

        $response->assertSee('Arrived 09:05');
        $response->assertSee('Wed, 30 Sep');
    }

    public function test_employee_balance_drilldown_lists_types_with_usage(): void
    {
        $this->travelTo(Carbon::parse('2026-09-30 10:00:00'));

        $ctx = $this->makeOrganization('DrillBal');
        app(WorkforceDefaults::class)->apply($ctx['organization']);
        $user = $this->makeUser($ctx['organization'], 'employee', $ctx['roles']);
        $employee = $this->makeEmployee($ctx['organization'], $user, 'EMP-DB');

        $leaveType = $this->leaveType($ctx['organization']->id);
        $balance = new LeaveBalance([
            'year' => 2026,
            'total_days' => 20,
            'used_days' => 3,
        ]);
        $balance->organization()->associate($ctx['organization']);
        $balance->employee()->associate($employee);
        $balance->leaveType()->associate($leaveType);
        $balance->save();

        $response = $this->actingAs($user)->get(route('dashboard.employee'));
        $response->assertOk();

        $drilldowns = $response->viewData('drilldowns');

        $row = collect($drilldowns['balance']['rows'])->first();
        $this->assertSame($leaveType->name, $row['name']);
        $this->assertSame('used 3 of 20 d', $row['detail']);
    }

    private function leaveType(int $orgId): LeaveType
    {
        return LeaveType::withoutGlobalScopes()
            ->where('organization_id', $orgId)
            ->where('code', 'ANNUAL')
            ->firstOrFail();
    }
}
