<?php

namespace Tests\Feature;

use App\Models\DailyAttendance;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Services\LiveAttendanceBoard;
use App\Services\WorkforceDefaults;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesOrganizations;
use Tests\TestCase;

class LiveAttendanceTest extends TestCase
{
    use CreatesOrganizations, RefreshDatabase;

    public function test_live_board_and_streams_require_attendance_view(): void
    {
        ['organization' => $org, 'roles' => $roles] = $this->makeOrganization();
        $employee = $this->makeUser($org, 'employee', $roles);
        $admin = $this->makeUser($org, 'company_admin', $roles);

        $this->actingAs($employee)->get(route('attendance.live.index'))->assertForbidden();
        $this->actingAs($employee)->get(route('attendance.live.stream'))->assertForbidden();
        $this->actingAs($employee)->get(route('attendance.live.poll'))->assertForbidden();

        $this->actingAs($admin)
            ->get(route('attendance.live.index'))
            ->assertOk()
            ->assertSee('Live Attendance')
            ->assertSee('Live board');

        $this->actingAs($employee)
            ->get(route('dashboard.employee'))
            ->assertOk()
            ->assertDontSee('Live board');
    }

    public function test_board_rows_cover_present_leave_absent_and_running_overtime(): void
    {
        Carbon::setTestNow('2026-09-29 18:30:00');

        ['organization' => $org, 'roles' => $roles] = $this->makeOrganization();
        app(WorkforceDefaults::class)->apply($org);

        $presentUser = $this->makeUser($org, 'employee', $roles, ['name' => 'Present Pat']);
        $present = $this->makeEmployee($org, $presentUser, 'EMP-LP');
        $leaveUser = $this->makeUser($org, 'employee', $roles, ['name' => 'Leave Lena']);
        $leave = $this->makeEmployee($org, $leaveUser, 'EMP-LL');
        $absentUser = $this->makeUser($org, 'employee', $roles, ['name' => 'Absent Amy']);
        $absent = $this->makeEmployee($org, $absentUser, 'EMP-LA');

        DailyAttendance::create([
            'organization_id' => $org->id,
            'employee_id' => $present->id,
            'date' => now()->toDateString(),
            'first_check_in' => now()->toDateString().' 09:02:00',
            'status' => 'PRESENT',
        ]);

        $annual = LeaveType::withoutGlobalScopes()
            ->where('organization_id', $org->id)
            ->where('code', 'ANNUAL')
            ->firstOrFail();

        $request = new LeaveRequest([
            'start_date' => now()->toDateString(),
            'end_date' => now()->toDateString(),
            'days' => 1,
            'reason' => 'Trip',
            'status' => LeaveRequest::APPROVED,
        ]);
        $request->organization()->associate($org);
        $request->employee()->associate($leave);
        $request->leaveType()->associate($annual);
        $request->save();

        $rows = collect(app(LiveAttendanceBoard::class)->rows($org));

        $pat = $rows->firstWhere('employee_id', $present->id);
        $this->assertSame('PRESENT', $pat['status']);
        $this->assertSame('09:02', $pat['time']);
        $this->assertTrue($pat['is_overtime']);
        $this->assertSame('+00:30', $pat['overtime_label']);

        $lena = $rows->firstWhere('employee_id', $leave->id);
        $this->assertSame('LEAVE', $lena['status']);
        $this->assertFalse($lena['is_overtime']);

        $amy = $rows->firstWhere('employee_id', $absent->id);
        $this->assertSame('ABSENT', $amy['status']);
        $this->assertNull($amy['time']);
        $this->assertFalse($amy['is_overtime']);
    }

    public function test_stream_emits_a_sse_snapshot_and_closes(): void
    {
        config()->set('live.stream_seconds', 0);

        ['organization' => $org, 'roles' => $roles] = $this->makeOrganization();
        $admin = $this->makeUser($org, 'company_admin', $roles);
        $user = $this->makeUser($org, 'employee', $roles, ['name' => 'Stream Sam']);
        $employee = $this->makeEmployee($org, $user, 'EMP-LS');

        DailyAttendance::create([
            'organization_id' => $org->id,
            'employee_id' => $employee->id,
            'date' => now()->toDateString(),
            'first_check_in' => now()->toDateString().' 09:02:00',
            'status' => 'PRESENT',
        ]);

        $response = $this->actingAs($admin)->get(route('attendance.live.stream'));

        $response->assertOk();
        $this->assertStringContainsString(
            'text/event-stream',
            (string) $response->headers->get('Content-Type')
        );

        $content = $response->streamedContent();
        $this->assertStringContainsString('retry: 3000', $content);
        $this->assertStringContainsString('data: {', $content);
        $this->assertStringContainsString('Stream Sam', $content);
        $this->assertStringContainsString('"status":"PRESENT"', $content);
    }

    public function test_poll_returns_a_snapshot_then_reports_unchanged_until_the_board_changes(): void
    {
        ['organization' => $org, 'roles' => $roles] = $this->makeOrganization();
        $admin = $this->makeUser($org, 'company_admin', $roles);

        $caraUser = $this->makeUser($org, 'employee', $roles, ['name' => 'Checked Cara']);
        $cara = $this->makeEmployee($org, $caraUser, 'EMP-LC');
        $leoUser = $this->makeUser($org, 'employee', $roles, ['name' => 'Later Leo']);
        $leo = $this->makeEmployee($org, $leoUser, 'EMP-LZ');

        DailyAttendance::create([
            'organization_id' => $org->id,
            'employee_id' => $cara->id,
            'date' => now()->toDateString(),
            'first_check_in' => now()->toDateString().' 09:02:00',
            'status' => 'PRESENT',
        ]);

        $first = $this->actingAs($admin)->getJson(route('attendance.live.poll'));
        $first->assertOk()->assertJsonPath('changed', true);

        $hash = $first->json('hash');
        $this->assertNotEmpty($hash);

        $unchanged = $this->getJson(route('attendance.live.poll', ['after' => $hash]));
        $unchanged->assertOk()
            ->assertJsonPath('changed', false)
            ->assertJsonPath('hash', $hash);

        DailyAttendance::create([
            'organization_id' => $org->id,
            'employee_id' => $leo->id,
            'date' => now()->toDateString(),
            'first_check_in' => now()->toDateString().' 09:15:00',
            'status' => 'PRESENT',
        ]);

        $changed = $this->getJson(route('attendance.live.poll', ['after' => $hash]));
        $changed->assertOk()->assertJsonPath('changed', true);

        $leoRow = collect($changed->json('rows'))->firstWhere('employee_id', $leo->id);
        $this->assertSame('PRESENT', $leoRow['status']);
        $this->assertSame('09:15', $leoRow['time']);
    }
}
