<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AttendanceEvent;
use App\Models\AuditLog;
use App\Models\DailyAttendance;
use App\Models\Employee;
use App\Models\User;
use App\Services\AttendanceService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesOrganizations;
use Tests\TestCase;

class SelfAttendanceTest extends TestCase
{
    use CreatesOrganizations;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Freeze mid-afternoon so "09:00"/"17:30" are always in the past and
        // "19:00" always in the future, regardless of when the suite runs.
        Carbon::setTestNow(Carbon::parse('2026-06-15 18:00:00'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /** @return array{0: User, 1: Employee} */
    private function makeSelfReportable(array $ctx, string $code = 'EMP-SR1'): array
    {
        $user = $this->makeUser($ctx['organization'], 'employee', $ctx['roles']);
        $employee = $this->makeEmployee($ctx['organization'], $user, $code);

        return [$user, $employee];
    }

    private function enableSelfAttendance(array $ctx): void
    {
        $ctx['organization']->update([
            'settings' => array_merge($ctx['organization']->settings ?? [], [
                'self_attendance_enabled' => true,
            ]),
        ]);
    }

    public function test_employee_page_hides_form_when_toggle_off(): void
    {
        $ctx = $this->makeOrganization('SelfOff');
        [$user, $employee] = $this->makeSelfReportable($ctx);

        $this->actingAs($user)
            ->get(route('attendance.employee', ['employee' => $employee->id]))
            ->assertOk()
            ->assertDontSee('Report attendance (terminal fallback)');
    }

    public function test_post_is_404_when_toggle_off(): void
    {
        $ctx = $this->makeOrganization('SelfOff2');
        [$user] = $this->makeSelfReportable($ctx);

        $this->actingAs($user)
            ->post(route('attendance.selfReport'), ['direction' => 'in', 'time' => '09:00'])
            ->assertNotFound();

        $this->assertSame(0, AttendanceEvent::withoutGlobalScopes()->count());
    }

    public function test_admin_can_enable_toggle_from_settings(): void
    {
        $ctx = $this->makeOrganization('SelfToggle');
        $admin = $this->makeUser($ctx['organization'], 'company_admin', $ctx['roles']);

        $this->actingAs($admin)->put(route('settings.update'), [
            'name' => $ctx['organization']->name,
            'timezone' => 'UTC',
            'debounce_seconds' => 15,
            'time_format' => '24h',
            'self_attendance_enabled' => '1',
        ])->assertRedirect(route('settings.edit'));

        $this->assertTrue($ctx['organization']->fresh()->selfAttendanceEnabled());

        $this->assertDatabaseHas('audit_logs', [
            'organization_id' => $ctx['organization']->id,
            'action' => 'organization.updated',
        ]);
    }

    public function test_employee_sees_form_when_toggle_on(): void
    {
        $ctx = $this->makeOrganization('SelfOn');
        $this->enableSelfAttendance($ctx);
        [$user, $employee] = $this->makeSelfReportable($ctx);

        $this->actingAs($user)
            ->get(route('attendance.employee', ['employee' => $employee->id]))
            ->assertOk()
            ->assertSee('Report attendance (terminal fallback)');
    }

    public function test_manager_viewing_other_employee_does_not_see_form(): void
    {
        $ctx = $this->makeOrganization('SelfOther');
        $this->enableSelfAttendance($ctx);
        [, $employee] = $this->makeSelfReportable($ctx);
        $manager = $this->makeUser($ctx['organization'], 'manager', $ctx['roles']);

        $this->actingAs($manager)
            ->get(route('attendance.employee', ['employee' => $employee->id]))
            ->assertOk()
            ->assertDontSee('Report attendance (terminal fallback)');
    }

    public function test_self_report_check_in_creates_event_and_derives_day(): void
    {
        $ctx = $this->makeOrganization('SelfIn');
        $this->enableSelfAttendance($ctx);
        [$user, $employee] = $this->makeSelfReportable($ctx);

        $this->actingAs($user)
            ->post(route('attendance.selfReport'), ['direction' => 'in', 'time' => '09:00'])
            ->assertRedirect(route('attendance.employee', ['employee' => $employee->id]));

        $event = AttendanceEvent::withoutGlobalScopes()
            ->where('employee_id', $employee->id)
            ->firstOrFail();

        $this->assertSame(AttendanceEvent::MANUAL_IN, $event->event_type);
        $this->assertSame('self', $event->source);
        $this->assertSame('2026-06-15 09:00:00', $event->occurred_at->toDateTimeString());

        $daily = DailyAttendance::where('employee_id', $employee->id)->firstOrFail();
        $this->assertSame('09:00:00', $daily->first_check_in->format('H:i:s'));

        $this->assertDatabaseHas('audit_logs', [
            'organization_id' => $ctx['organization']->id,
            'action' => 'attendance.self_reported',
        ]);
    }

    public function test_second_check_in_same_day_is_rejected(): void
    {
        $ctx = $this->makeOrganization('SelfDup');
        $this->enableSelfAttendance($ctx);
        [$user, $employee] = $this->makeSelfReportable($ctx);

        $this->actingAs($user)
            ->post(route('attendance.selfReport'), ['direction' => 'in', 'time' => '09:00'])
            ->assertRedirect();

        $this->actingAs($user)
            ->from(route('attendance.employee', ['employee' => $employee->id]))
            ->post(route('attendance.selfReport'), ['direction' => 'in', 'time' => '11:00'])
            ->assertSessionHasErrors('time');

        $this->assertSame(1, AttendanceEvent::withoutGlobalScopes()
            ->where('employee_id', $employee->id)
            ->where('event_type', AttendanceEvent::MANUAL_IN)
            ->count());
    }

    public function test_check_out_can_be_reported_once(): void
    {
        $ctx = $this->makeOrganization('SelfOut');
        $this->enableSelfAttendance($ctx);
        [$user, $employee] = $this->makeSelfReportable($ctx);

        $this->actingAs($user)
            ->post(route('attendance.selfReport'), ['direction' => 'out', 'time' => '17:30'])
            ->assertRedirect();

        $this->actingAs($user)
            ->post(route('attendance.selfReport'), ['direction' => 'out', 'time' => '18:00'])
            ->assertSessionHasErrors('time');

        $this->assertSame(1, AttendanceEvent::withoutGlobalScopes()
            ->where('employee_id', $employee->id)
            ->where('event_type', AttendanceEvent::MANUAL_OUT)
            ->count());
    }

    public function test_in_and_out_can_both_be_reported_same_day(): void
    {
        $ctx = $this->makeOrganization('SelfBoth');
        $this->enableSelfAttendance($ctx);
        [$user, $employee] = $this->makeSelfReportable($ctx);

        $this->actingAs($user)
            ->post(route('attendance.selfReport'), ['direction' => 'in', 'time' => '09:00'])
            ->assertRedirect();
        $this->actingAs($user)
            ->post(route('attendance.selfReport'), ['direction' => 'out', 'time' => '17:00'])
            ->assertRedirect();

        $daily = DailyAttendance::where('employee_id', $employee->id)->firstOrFail();
        $this->assertSame('09:00:00', $daily->first_check_in->format('H:i:s'));
        $this->assertSame('17:00:00', $daily->last_check_out->format('H:i:s'));
        $this->assertTrue((bool) $daily->is_manual);
    }

    public function test_future_time_is_rejected(): void
    {
        $ctx = $this->makeOrganization('SelfFuture');
        $this->enableSelfAttendance($ctx);
        [$user] = $this->makeSelfReportable($ctx);

        $this->actingAs($user)
            ->post(route('attendance.selfReport'), [
                'direction' => 'in',
                'time' => '19:00',
            ])
            ->assertSessionHasErrors('time');

        $this->assertSame(0, AttendanceEvent::withoutGlobalScopes()->count());
    }

    public function test_bad_direction_is_rejected(): void
    {
        $ctx = $this->makeOrganization('SelfBadDir');
        $this->enableSelfAttendance($ctx);
        [$user] = $this->makeSelfReportable($ctx);

        $this->actingAs($user)
            ->post(route('attendance.selfReport'), ['direction' => 'sideways', 'time' => '09:00'])
            ->assertSessionHasErrors('direction');
    }

    public function test_user_without_employee_profile_gets_404(): void
    {
        $ctx = $this->makeOrganization('SelfNoEmp');
        $this->enableSelfAttendance($ctx);
        $user = $this->makeUser($ctx['organization'], 'employee', $ctx['roles']);

        $this->actingAs($user)
            ->post(route('attendance.selfReport'), ['direction' => 'in', 'time' => '09:00'])
            ->assertNotFound();
    }

    public function test_self_reported_event_is_immutable_from_employee_side(): void
    {
        $ctx = $this->makeOrganization('SelfLock');
        $this->enableSelfAttendance($ctx);
        [$user, $employee] = $this->makeSelfReportable($ctx);

        $this->actingAs($user)
            ->post(route('attendance.selfReport'), ['direction' => 'in', 'time' => '09:00'])
            ->assertRedirect();

        $event = AttendanceEvent::withoutGlobalScopes()
            ->where('employee_id', $employee->id)
            ->firstOrFail();

        // No employee-side edit/delete exists; admin routes refuse plain employees.
        $this->actingAs($user)
            ->put(route('attendance.events.update', ['attendanceEvent' => $event->id]), [
                'event_type' => AttendanceEvent::MANUAL_IN,
                'occurred_at' => now()->toIso8601String(),
                'reason' => 'try to edit',
            ])
            ->assertForbidden();

        $this->actingAs($user)
            ->delete(route('attendance.events.destroy', ['attendanceEvent' => $event->id]))
            ->assertForbidden();

        $this->assertSame('2026-06-15 09:00:00', $event->fresh()->occurred_at->toDateTimeString());
    }

    public function test_admin_can_still_correct_a_self_reported_event(): void
    {
        $ctx = $this->makeOrganization('SelfFix');
        $this->enableSelfAttendance($ctx);
        [$user, $employee] = $this->makeSelfReportable($ctx);
        $admin = $this->makeUser($ctx['organization'], 'company_admin', $ctx['roles']);

        $this->actingAs($user)
            ->post(route('attendance.selfReport'), ['direction' => 'in', 'time' => '09:00'])
            ->assertRedirect();

        $event = AttendanceEvent::withoutGlobalScopes()
            ->where('employee_id', $employee->id)
            ->firstOrFail();

        $replacement = app(AttendanceService::class)->replaceEvent(
            $event,
            AttendanceEvent::MANUAL_IN,
            now()->setTime(8, 45),
            'Terminal clock was wrong',
            $admin->id,
        );

        $this->assertNotSame($event->id, $replacement->id);
        $this->assertSame($replacement->id, $event->fresh()->superseded_by);
        $this->assertSame('08:45:00', $replacement->occurred_at->format('H:i:s'));
    }

    public function test_toggle_off_after_reports_hides_form_but_keeps_events(): void
    {
        $ctx = $this->makeOrganization('SelfOffAfter');
        $this->enableSelfAttendance($ctx);
        [$user, $employee] = $this->makeSelfReportable($ctx);

        $this->actingAs($user)
            ->post(route('attendance.selfReport'), ['direction' => 'in', 'time' => '09:00'])
            ->assertRedirect();

        $ctx['organization']->update([
            'settings' => array_merge($ctx['organization']->settings ?? [], [
                'self_attendance_enabled' => false,
            ]),
        ]);

        $this->actingAs($user)
            ->get(route('attendance.employee', ['employee' => $employee->id]))
            ->assertOk()
            ->assertDontSee('Report attendance (terminal fallback)');

        $this->assertSame(1, AttendanceEvent::withoutGlobalScopes()
            ->where('employee_id', $employee->id)
            ->count());

        $this->actingAs($user)
            ->post(route('attendance.selfReport'), ['direction' => 'out', 'time' => '17:00'])
            ->assertNotFound();
    }

    public function test_daily_audit_log_records_self_report(): void
    {
        $ctx = $this->makeOrganization('SelfAudit');
        $this->enableSelfAttendance($ctx);
        [$user, $employee] = $this->makeSelfReportable($ctx);

        $this->actingAs($user)
            ->post(route('attendance.selfReport'), ['direction' => 'in', 'time' => '10:15'])
            ->assertRedirect();

        $event = AttendanceEvent::withoutGlobalScopes()
            ->where('employee_id', $employee->id)
            ->firstOrFail();

        $log = AuditLog::where('organization_id', $ctx['organization']->id)
            ->where('action', 'attendance.self_reported')
            ->firstOrFail();

        $this->assertSame($user->id, $log->actor_user_id);
        $this->assertSame((int) $log->resource_id, $event->id);
        $this->assertSame('AttendanceEvent', $log->resource_type);
    }
}
