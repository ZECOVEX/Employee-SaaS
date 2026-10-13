<?php

namespace Tests\Feature;

use App\Models\DailyAttendance;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveNotice;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\User;
use App\Notifications\LeaveNoticeAdded;
use App\Notifications\LeaveStatusChanged;
use App\Services\LeaveService;
use App\Services\WorkforceDefaults;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\CreatesOrganizations;
use Tests\TestCase;

class LeaveReviewEditTest extends TestCase
{
    use CreatesOrganizations;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-06-15 12:00:00'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * @return array{0: Employee, 1: LeaveType, 2: User, 3: User}
     */
    private function bootLeave(array $ctx): array
    {
        app(WorkforceDefaults::class)->apply($ctx['organization']);

        $user = $this->makeUser($ctx['organization'], 'employee', $ctx['roles']);
        $employee = $this->makeEmployee($ctx['organization'], $user, 'EMP-RR');

        $leaveType = LeaveType::withoutGlobalScopes()
            ->where('organization_id', $ctx['organization']->id)
            ->where('code', 'ANNUAL')
            ->firstOrFail();

        app(LeaveService::class)->ensureBalances($employee);

        $admin = $this->makeUser($ctx['organization'], 'company_admin', $ctx['roles']);

        return [$employee, $leaveType, $user, $admin];
    }

    private function makePendingRequest(array $ctx, Employee $employee, LeaveType $type, int $days = 2, ?Carbon $start = null): LeaveRequest
    {
        $start ??= Carbon::now()->addWeek()->startOfDay();

        $request = new LeaveRequest([
            'start_date' => $start->toDateString(),
            'end_date' => $start->copy()->addDays($days - 1)->toDateString(),
            'days' => $days,
            'reason' => 'Trip',
            'status' => LeaveRequest::PENDING,
        ]);
        $request->organization()->associate($ctx['organization']);
        $request->employee()->associate($employee);
        $request->leaveType()->associate($type);
        $request->save();

        return $request;
    }

    private function balance(array $ctx, Employee $employee, LeaveType $type): LeaveBalance
    {
        return LeaveBalance::withoutGlobalScopes()
            ->where('organization_id', $ctx['organization']->id)
            ->where('employee_id', $employee->id)
            ->where('leave_type_id', $type->id)
            ->firstOrFail();
    }

    public function test_approver_can_reject_an_approved_request_and_balance_is_refunded(): void
    {
        $ctx = $this->makeOrganization('ReRev1');
        [$employee, $type, , $admin] = $this->bootLeave($ctx);
        $service = app(LeaveService::class);
        $this->actingAs($admin);

        $request = $this->makePendingRequest($ctx, $employee, $type, 2);
        $service->approve($request, $admin->id);
        $this->assertSame(2, $this->balance($ctx, $employee, $type)->used_days);

        $this->actingAs($admin)
            ->post(route('leave.reject', $request), ['review_note' => 'Coverage problem'])
            ->assertRedirect();

        $fresh = $request->fresh();
        $this->assertSame(LeaveRequest::REJECTED, $fresh->status);
        $this->assertSame('Coverage problem', $fresh->review_note);
        $this->assertSame(0, $this->balance($ctx, $employee, $type)->fresh()->used_days);
    }

    public function test_approver_can_approve_a_rejected_request_and_balance_is_deducted(): void
    {
        $ctx = $this->makeOrganization('ReRev2');
        [$employee, $type, , $admin] = $this->bootLeave($ctx);
        $service = app(LeaveService::class);
        $this->actingAs($admin);

        $request = $this->makePendingRequest($ctx, $employee, $type, 3);
        $service->reject($request, $admin->id, 'First pass no');
        $this->assertSame(0, $this->balance($ctx, $employee, $type)->used_days);

        $this->actingAs($admin)
            ->post(route('leave.approve', $request), ['review_note' => 'Reversed, sorry'])
            ->assertRedirect();

        $this->assertSame(LeaveRequest::APPROVED, $request->fresh()->status);
        $this->assertSame(3, $this->balance($ctx, $employee, $type)->fresh()->used_days);
    }

    public function test_approving_an_already_approved_request_does_not_double_deduct(): void
    {
        Notification::fake();

        $ctx = $this->makeOrganization('ReRev3');
        [$employee, $type, $employeeUser, $admin] = $this->bootLeave($ctx);
        $service = app(LeaveService::class);
        $this->actingAs($admin);

        $request = $this->makePendingRequest($ctx, $employee, $type, 2);
        $service->approve($request, $admin->id, 'First approval');
        $service->approve($request, $admin->id, 'Second approval');

        $this->assertSame(2, $this->balance($ctx, $employee, $type)->fresh()->used_days);
        $this->assertSame('Second approval', $request->fresh()->review_note);
        Notification::assertSentToTimes($employeeUser, LeaveStatusChanged::class, 1);
    }

    public function test_rejecting_an_already_rejected_request_is_idempotent(): void
    {
        $ctx = $this->makeOrganization('ReRev4');
        [$employee, $type, , $admin] = $this->bootLeave($ctx);
        $service = app(LeaveService::class);
        $this->actingAs($admin);

        $request = $this->makePendingRequest($ctx, $employee, $type, 2);
        $service->reject($request, $admin->id, 'No');
        $service->reject($request, $admin->id, 'Still no');

        $fresh = $request->fresh();
        $this->assertSame(LeaveRequest::REJECTED, $fresh->status);
        $this->assertSame('Still no', $fresh->review_note);
        $this->assertSame(0, $this->balance($ctx, $employee, $type)->fresh()->used_days);
    }

    public function test_cancelled_request_cannot_be_reviewed(): void
    {
        $ctx = $this->makeOrganization('ReRev5');
        [$employee, $type, $employeeUser, $admin] = $this->bootLeave($ctx);
        $service = app(LeaveService::class);
        $this->actingAs($admin);

        $request = $this->makePendingRequest($ctx, $employee, $type, 1);
        $service->cancel($request);

        try {
            $service->approve($request, $admin->id);
            $this->fail('Expected RuntimeException for cancelled request.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('cancelled', $e->getMessage());
        }

        $this->actingAs($admin)
            ->post(route('leave.reject', $request))
            ->assertSessionHasErrors('leave');

        $this->assertSame(LeaveRequest::CANCELLED, $request->fresh()->status);
        $this->assertSame(0, $this->balance($ctx, $employee, $type)->fresh()->used_days);
    }

    public function test_rejecting_approved_request_removes_leave_attendance_stamps(): void
    {
        $ctx = $this->makeOrganization('ReRev6');
        [$employee, $type, , $admin] = $this->bootLeave($ctx);
        $service = app(LeaveService::class);
        $this->actingAs($admin);

        $start = Carbon::now()->addWeek()->startOfDay();
        $request = $this->makePendingRequest($ctx, $employee, $type, 2, $start);
        $service->approve($request, $admin->id);

        $this->assertSame(2, DailyAttendance::withoutGlobalScopes()
            ->where('employee_id', $employee->id)
            ->where('status', 'LEAVE')
            ->count());

        $service->reject($request, $admin->id, 'Site shutdown');

        $this->assertSame(0, DailyAttendance::withoutGlobalScopes()
            ->where('employee_id', $employee->id)
            ->where('status', 'LEAVE')
            ->count());
    }

    public function test_review_note_is_persisted_from_the_web_review_forms(): void
    {
        $ctx = $this->makeOrganization('ReRev7');
        [$employee, $type, $employeeUser, $admin] = $this->bootLeave($ctx);

        $request = $this->makePendingRequest($ctx, $employee, $type, 1);

        $this->actingAs($admin)
            ->post(route('leave.approve', $request), ['review_note' => 'Approved from the form'])
            ->assertRedirect();

        $this->assertSame('Approved from the form', $request->fresh()->review_note);

        // The employee sees the note on the show page.
        $this->actingAs($employeeUser)
            ->get(route('leave.show', $request))
            ->assertOk()
            ->assertSee('Approved from the form');
    }

    public function test_approver_can_add_a_notice_and_employee_sees_it_on_show_page(): void
    {
        Notification::fake();

        $ctx = $this->makeOrganization('ReRev8');
        [$employee, $type, $employeeUser, $admin] = $this->bootLeave($ctx);

        $request = $this->makePendingRequest($ctx, $employee, $type, 1);

        $this->actingAs($admin)
            ->post(route('leave.notice', $request), ['body' => 'Please bring the medical certificate.'])
            ->assertRedirect(route('leave.show', $request));

        $this->assertDatabaseHas('leave_notices', [
            'leave_request_id' => $request->id,
            'user_id' => $admin->id,
            'body' => 'Please bring the medical certificate.',
        ]);

        $this->actingAs($employeeUser)
            ->get(route('leave.show', $request))
            ->assertOk()
            ->assertSee('Please bring the medical certificate.')
            ->assertSee($admin->name);

        Notification::assertSentTo($employeeUser, LeaveNoticeAdded::class);
    }

    public function test_notices_accumulate_and_are_visible_in_order(): void
    {
        $ctx = $this->makeOrganization('ReRev9');
        [$employee, $type, $employeeUser, $admin] = $this->bootLeave($ctx);

        $request = $this->makePendingRequest($ctx, $employee, $type, 1);

        foreach (['First notice', 'Second notice', 'Third notice'] as $body) {
            $this->actingAs($admin)
                ->post(route('leave.notice', $request), ['body' => $body])
                ->assertRedirect();
        }

        $this->assertSame(3, LeaveNotice::withoutGlobalScopes()
            ->where('leave_request_id', $request->id)
            ->count());

        $response = $this->actingAs($employeeUser)->get(route('leave.show', $request));
        $response->assertOk()
            ->assertSee('First notice')
            ->assertSee('Second notice')
            ->assertSee('Third notice');
        $this->assertTrue(
            strpos($response->getContent(), 'First notice') < strpos($response->getContent(), 'Third notice'),
        );
    }

    public function test_employee_cannot_add_a_notice(): void
    {
        $ctx = $this->makeOrganization('ReRev10');
        [$employee, $type, $employeeUser] = $this->bootLeave($ctx);

        $request = $this->makePendingRequest($ctx, $employee, $type, 1);

        $this->actingAs($employeeUser)
            ->post(route('leave.notice', $request), ['body' => 'Self note'])
            ->assertForbidden();

        $this->assertDatabaseMissing('leave_notices', ['body' => 'Self note']);
    }

    public function test_notice_requires_a_body(): void
    {
        $ctx = $this->makeOrganization('ReRev11');
        [$employee, $type, , $admin] = $this->bootLeave($ctx);

        $request = $this->makePendingRequest($ctx, $employee, $type, 1);

        $this->actingAs($admin)
            ->post(route('leave.notice', $request), ['body' => ''])
            ->assertSessionHasErrors('body');

        $this->assertSame(0, LeaveNotice::withoutGlobalScopes()->count());
    }

    public function test_manager_can_review_and_notice_own_team_member_only(): void
    {
        $ctx = $this->makeOrganization('ReRev12');
        [$employee, $type] = $this->bootLeave($ctx);

        $manager = $this->makeUser($ctx['organization'], 'manager', $ctx['roles']);
        $employee->forceFill(['manager_id' => $manager->id])->save();

        // Outsider employee with their own request.
        $outsiderUser = $this->makeUser($ctx['organization'], 'employee', $ctx['roles']);
        $outsider = $this->makeEmployee($ctx['organization'], $outsiderUser, 'EMP-OUT');
        $outsiderRequest = $this->makePendingRequest($ctx, $outsider, $type, 1);

        $teamRequest = $this->makePendingRequest($ctx, $employee, $type, 1);

        // Outsider request: show + notice are both forbidden.
        $this->actingAs($manager)
            ->get(route('leave.show', $outsiderRequest))
            ->assertForbidden();
        $this->actingAs($manager)
            ->post(route('leave.notice', $outsiderRequest), ['body' => 'Not mine'])
            ->assertForbidden();

        // Team request: show + notice both work.
        $this->actingAs($manager)
            ->get(route('leave.show', $teamRequest))
            ->assertOk();
        $this->actingAs($manager)
            ->post(route('leave.notice', $teamRequest), ['body' => 'Team member note'])
            ->assertRedirect(route('leave.show', $teamRequest));

        $this->actingAs($manager)
            ->post(route('leave.reject', $teamRequest), ['review_note' => 'Covered by Ali'])
            ->assertRedirect();

        $this->assertSame(LeaveRequest::REJECTED, $teamRequest->fresh()->status);
    }

    public function test_employee_can_view_own_show_but_not_someone_elses(): void
    {
        $ctx = $this->makeOrganization('ReRev13');
        [$employee, $type, $ownerUser] = $this->bootLeave($ctx);

        $otherUser = $this->makeUser($ctx['organization'], 'employee', $ctx['roles']);
        $otherEmployee = $this->makeEmployee($ctx['organization'], $otherUser, 'EMP-OTH');
        $otherRequest = $this->makePendingRequest($ctx, $otherEmployee, $type, 1);

        $ownRequest = $this->makePendingRequest($ctx, $employee, $type, 1);

        $this->actingAs($ownerUser)
            ->get(route('leave.show', $ownRequest))
            ->assertOk();

        $this->actingAs($ownerUser)
            ->get(route('leave.show', $otherRequest))
            ->assertForbidden();
    }

    public function test_re_review_notifies_employee_only_on_status_change(): void
    {
        Notification::fake();

        $ctx = $this->makeOrganization('ReRev14');
        [$employee, $type, $employeeUser, $admin] = $this->bootLeave($ctx);
        $service = app(LeaveService::class);
        $this->actingAs($admin);

        $request = $this->makePendingRequest($ctx, $employee, $type, 1);

        $service->approve($request, $admin->id);
        $service->reject($request, $admin->id, 'Changed my mind');
        $service->reject($request, $admin->id, 'Still rejected');

        // approve + first reject notify; the no-op second reject does not.
        Notification::assertSentToTimes($employeeUser, LeaveStatusChanged::class, 2);
    }

    public function test_rejected_request_refund_frees_days_for_a_new_request(): void
    {
        $ctx = $this->makeOrganization('ReRev15');
        [$employee, $type, , $admin] = $this->bootLeave($ctx);
        $service = app(LeaveService::class);
        $this->actingAs($admin);

        // Consume almost the whole balance, then free it via reject-after-approve.
        $balance = $this->balance($ctx, $employee, $type);
        $balance->update(['total_days' => 2]);

        $first = $this->makePendingRequest($ctx, $employee, $type, 2);
        $service->approve($first, $admin->id);
        $this->assertSame(0, $this->balance($ctx, $employee, $type)->fresh()->remainingDays());

        $service->reject($first, $admin->id);
        $this->assertSame(2, $this->balance($ctx, $employee, $type)->fresh()->remainingDays());

        // A second request now fits again.
        $second = $this->makePendingRequest($ctx, $employee, $type, 2, Carbon::now()->addWeeks(3));
        $service->approve($second, $admin->id);
        $this->assertSame(LeaveRequest::APPROVED, $second->fresh()->status);
        $this->assertSame(0, $this->balance($ctx, $employee, $type)->fresh()->remainingDays());
    }
}
