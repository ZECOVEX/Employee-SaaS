<?php

namespace Tests\Feature;

use App\Models\DailyAttendance;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\User;
use App\Notifications\LeaveStatusChanged;
use App\Services\LeaveService;
use App\Services\WorkforceDefaults;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\CreatesOrganizations;
use Tests\TestCase;

class LeaveEditTest extends TestCase
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
        $employee = $this->makeEmployee($ctx['organization'], $user, 'EMP-ED');

        $leaveType = LeaveType::withoutGlobalScopes()
            ->where('organization_id', $ctx['organization']->id)
            ->where('code', 'ANNUAL')
            ->firstOrFail();

        app(LeaveService::class)->ensureBalances($employee, (int) Carbon::now()->year);

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

    private function balance(array $ctx, Employee $employee, LeaveType $type, ?int $year = null): LeaveBalance
    {
        $query = LeaveBalance::withoutGlobalScopes()
            ->where('organization_id', $ctx['organization']->id)
            ->where('employee_id', $employee->id)
            ->where('leave_type_id', $type->id);

        if ($year !== null) {
            $query->where('year', $year);
        }

        return $query->firstOrFail();
    }

    private function leaveStamps(Employee $employee): int
    {
        return DailyAttendance::withoutGlobalScopes()
            ->where('employee_id', $employee->id)
            ->where('status', 'LEAVE')
            ->count();
    }

    public function test_admin_can_reduce_an_approved_leave_refunding_balance_and_moving_stamps(): void
    {
        Notification::fake();

        $ctx = $this->makeOrganization('Edit1');
        [$employee, $type, $employeeUser, $admin] = $this->bootLeave($ctx);
        $service = app(LeaveService::class);
        $this->actingAs($admin);

        // Mon 22 Jun – Fri 26 Jun = 5 working days.
        $request = $this->makePendingRequest($ctx, $employee, $type, 5);
        $service->approve($request, $admin->id);
        $this->assertSame(5, $this->balance($ctx, $employee, $type)->fresh()->used_days);
        $this->assertSame(5, $this->leaveStamps($employee));

        $this->actingAs($admin)
            ->get(route('leave.edit', $request))
            ->assertOk()
            ->assertSee('Edit Leave Request');

        // Shrink to Mon 22 Jun – Wed 24 Jun = 3 working days.
        $this->actingAs($admin)
            ->from(route('leave.show', $request))
            ->put(route('leave.update', $request), [
                'leave_type_id' => $type->id,
                'start_date' => '2026-06-22',
                'end_date' => '2026-06-24',
                'reason' => 'Trip shortened',
            ])
            ->assertRedirect(route('leave.show', $request));

        $fresh = $request->fresh();
        $this->assertSame(LeaveRequest::APPROVED, $fresh->status);
        $this->assertSame(3, $fresh->days);
        $this->assertSame('Trip shortened', $fresh->reason);
        $this->assertSame(3, $this->balance($ctx, $employee, $type)->fresh()->used_days);

        // Stamps moved: 25/26 Jun are gone, 22–24 Jun remain.
        $this->assertSame(3, $this->leaveStamps($employee));
        $this->assertSame(0, DailyAttendance::withoutGlobalScopes()
            ->where('employee_id', $employee->id)
            ->whereBetween('date', ['2026-06-25', '2026-06-26'])
            ->where('status', 'LEAVE')
            ->count());

        $this->assertDatabaseHas('audit_logs', ['action' => 'leave.edited']);

        Notification::assertSentToTimes($employeeUser, LeaveStatusChanged::class, 2);
        Notification::assertSentTo(
            $employeeUser,
            LeaveStatusChanged::class,
            fn (LeaveStatusChanged $n) => $n->title() === 'Leave updated',
        );
    }

    public function test_edit_form_renders_with_current_values(): void
    {
        $ctx = $this->makeOrganization('Edit2');
        [$employee, $type, , $admin] = $this->bootLeave($ctx);

        $request = $this->makePendingRequest($ctx, $employee, $type, 2);

        $this->actingAs($admin)
            ->get(route('leave.edit', $request))
            ->assertOk()
            ->assertSee('Edit Leave Request')
            ->assertSee('Annual Leave')
            ->assertSee('2026-06-22')
            ->assertSee('2026-06-23');
    }

    public function test_admin_can_extend_an_approved_leave_within_balance(): void
    {
        $ctx = $this->makeOrganization('Edit3');
        [$employee, $type, , $admin] = $this->bootLeave($ctx);
        $service = app(LeaveService::class);
        $this->actingAs($admin);

        $request = $this->makePendingRequest($ctx, $employee, $type, 2);
        $service->approve($request, $admin->id);
        $this->assertSame(2, $this->balance($ctx, $employee, $type)->fresh()->used_days);

        // Mon 22 Jun – Mon 29 Jun = 6 working days (Mon–Fri + next Mon).
        $this->actingAs($admin)
            ->put(route('leave.update', $request), [
                'leave_type_id' => $type->id,
                'start_date' => '2026-06-22',
                'end_date' => '2026-06-29',
            ])
            ->assertRedirect(route('leave.show', $request));

        $fresh = $request->fresh();
        $this->assertSame(LeaveRequest::APPROVED, $fresh->status);
        $this->assertSame(6, $fresh->days);
        $this->assertSame(6, $this->balance($ctx, $employee, $type)->fresh()->used_days);
        // Stamps cover the whole calendar range (weekends included), like approve().
        $this->assertSame(8, $this->leaveStamps($employee));
    }

    public function test_extending_approved_leave_beyond_balance_fails_and_rolls_back(): void
    {
        $ctx = $this->makeOrganization('Edit4');
        [$employee, $type, , $admin] = $this->bootLeave($ctx);
        $service = app(LeaveService::class);
        $this->actingAs($admin);

        $this->balance($ctx, $employee, $type)->update(['total_days' => 5]);

        $request = $this->makePendingRequest($ctx, $employee, $type, 3);
        $service->approve($request, $admin->id);
        $this->assertSame(3, $this->balance($ctx, $employee, $type)->fresh()->used_days);
        $this->assertSame(3, $this->leaveStamps($employee));

        // Mon 22 Jun – Mon 29 Jun = 6 working days > 5 total.
        $this->actingAs($admin)
            ->from(route('leave.show', $request))
            ->put(route('leave.update', $request), [
                'leave_type_id' => $type->id,
                'start_date' => '2026-06-22',
                'end_date' => '2026-06-29',
            ])
            ->assertRedirect(route('leave.show', $request))
            ->assertSessionHasErrors('leave');

        $fresh = $request->fresh();
        $this->assertSame(LeaveRequest::APPROVED, $fresh->status);
        $this->assertSame(3, $fresh->days);
        $this->assertSame(3, $this->balance($ctx, $employee, $type)->fresh()->used_days);
        $this->assertSame(3, $this->leaveStamps($employee));
    }

    public function test_editing_approved_leave_can_move_days_to_another_type(): void
    {
        $ctx = $this->makeOrganization('Edit5');
        [$employee, $type, , $admin] = $this->bootLeave($ctx);
        $service = app(LeaveService::class);
        $this->actingAs($admin);

        $sick = LeaveType::withoutGlobalScopes()
            ->where('organization_id', $ctx['organization']->id)
            ->where('code', 'SICK')
            ->firstOrFail();

        $request = $this->makePendingRequest($ctx, $employee, $type, 3);
        $service->approve($request, $admin->id);
        $this->assertSame(3, $this->balance($ctx, $employee, $type)->fresh()->used_days);

        $this->actingAs($admin)
            ->put(route('leave.update', $request), [
                'leave_type_id' => $sick->id,
                'start_date' => '2026-06-22',
                'end_date' => '2026-06-24',
                'reason' => 'Recategorized as sick',
            ])
            ->assertRedirect(route('leave.show', $request));

        $fresh = $request->fresh();
        $this->assertSame(LeaveRequest::APPROVED, $fresh->status);
        $this->assertSame($sick->id, $fresh->leave_type_id);
        $this->assertSame(3, $fresh->days);
        $this->assertSame(0, $this->balance($ctx, $employee, $type)->fresh()->used_days);
        $this->assertSame(3, $this->balance($ctx, $employee, $sick)->fresh()->used_days);
        $this->assertSame(3, $this->leaveStamps($employee));
    }

    public function test_year_boundary_edit_refunds_the_old_year_and_deducts_the_new_one(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-12-20 12:00:00'));

        $ctx = $this->makeOrganization('Edit6');
        [$employee, $type, , $admin] = $this->bootLeave($ctx);
        $service = app(LeaveService::class);
        $this->actingAs($admin);

        // Wed 30 Dec – Thu 31 Dec 2026 = 2 working days in the 2026 bucket.
        $request = $this->makePendingRequest($ctx, $employee, $type, 2, Carbon::parse('2026-12-30'));
        $service->approve($request, $admin->id);
        $this->assertSame(2, $this->balance($ctx, $employee, $type, 2026)->fresh()->used_days);

        // Move to Mon 4 Jan – Tue 5 Jan 2027 = 2 working days in the 2027 bucket.
        $this->actingAs($admin)
            ->put(route('leave.update', $request), [
                'leave_type_id' => $type->id,
                'start_date' => '2027-01-04',
                'end_date' => '2027-01-05',
            ])
            ->assertRedirect(route('leave.show', $request));

        $this->assertSame(2, $request->fresh()->days);
        $this->assertSame(0, $this->balance($ctx, $employee, $type, 2026)->fresh()->used_days);
        $this->assertSame(2, $this->balance($ctx, $employee, $type, 2027)->fresh()->used_days);

        // Stamps left 2026 and landed in 2027.
        $this->assertSame(0, DailyAttendance::withoutGlobalScopes()
            ->where('employee_id', $employee->id)
            ->whereBetween('date', ['2026-12-30', '2026-12-31'])
            ->where('status', 'LEAVE')
            ->count());
        $this->assertSame(2, DailyAttendance::withoutGlobalScopes()
            ->where('employee_id', $employee->id)
            ->whereBetween('date', ['2027-01-04', '2027-01-05'])
            ->where('status', 'LEAVE')
            ->count());
    }

    public function test_pending_and_rejected_requests_edit_without_touching_the_balance(): void
    {
        $ctx = $this->makeOrganization('Edit7');
        [$employee, $type, , $admin] = $this->bootLeave($ctx);
        $service = app(LeaveService::class);
        $this->actingAs($admin);

        $pending = $this->makePendingRequest($ctx, $employee, $type, 2);

        // Past dates are fine — admins may edit at any time.
        $this->actingAs($admin)
            ->put(route('leave.update', $pending), [
                'leave_type_id' => $type->id,
                'start_date' => '2026-06-01',
                'end_date' => '2026-06-03',
            ])
            ->assertRedirect(route('leave.show', $pending));

        $fresh = $pending->fresh();
        $this->assertSame(LeaveRequest::PENDING, $fresh->status);
        $this->assertSame(3, $fresh->days);
        $this->assertSame(0, $this->balance($ctx, $employee, $type)->fresh()->used_days);
        $this->assertSame(0, $this->leaveStamps($employee));

        // Rejected: still no balance or stamp impact.
        $rejected = $this->makePendingRequest($ctx, $employee, $type, 1);
        $service->reject($rejected, $admin->id, 'No');

        $this->actingAs($admin)
            ->put(route('leave.update', $rejected), [
                'leave_type_id' => $type->id,
                'start_date' => '2026-07-06',
                'end_date' => '2026-07-08',
            ])
            ->assertRedirect(route('leave.show', $rejected));

        $fresh = $rejected->fresh();
        $this->assertSame(LeaveRequest::REJECTED, $fresh->status);
        $this->assertSame(3, $fresh->days);
        $this->assertSame(0, $this->balance($ctx, $employee, $type)->fresh()->used_days);
        $this->assertSame(0, $this->leaveStamps($employee));
    }

    public function test_cancelled_request_cannot_be_edited(): void
    {
        $ctx = $this->makeOrganization('Edit8');
        [$employee, $type, , $admin] = $this->bootLeave($ctx);
        $service = app(LeaveService::class);
        $this->actingAs($admin);

        $request = $this->makePendingRequest($ctx, $employee, $type, 2);
        $service->cancel($request);

        $this->actingAs($admin)
            ->get(route('leave.edit', $request))
            ->assertForbidden();

        $this->actingAs($admin)
            ->put(route('leave.update', $request), [
                'leave_type_id' => $type->id,
                'start_date' => '2026-06-22',
                'end_date' => '2026-06-23',
            ])
            ->assertForbidden();

        $this->assertSame(LeaveRequest::CANCELLED, $request->fresh()->status);
    }

    public function test_employee_cannot_edit_leave_requests(): void
    {
        $ctx = $this->makeOrganization('Edit9');
        [$employee, $type, $employeeUser] = $this->bootLeave($ctx);

        $request = $this->makePendingRequest($ctx, $employee, $type, 2);

        $this->actingAs($employeeUser)
            ->get(route('leave.edit', $request))
            ->assertForbidden();

        $this->actingAs($employeeUser)
            ->put(route('leave.update', $request), [
                'leave_type_id' => $type->id,
                'start_date' => '2026-06-22',
                'end_date' => '2026-06-24',
            ])
            ->assertForbidden();

        $fresh = $request->fresh();
        $this->assertSame(2, $fresh->days);
        $this->assertSame('2026-06-22', $fresh->start_date->toDateString());
    }

    public function test_manager_can_edit_team_members_request_but_not_other_teams(): void
    {
        $ctx = $this->makeOrganization('Edit10');
        [$employee, $type] = $this->bootLeave($ctx);

        $manager = $this->makeUser($ctx['organization'], 'manager', $ctx['roles']);
        $employee->forceFill(['manager_id' => $manager->id])->save();

        $outsiderUser = $this->makeUser($ctx['organization'], 'employee', $ctx['roles']);
        $outsider = $this->makeEmployee($ctx['organization'], $outsiderUser, 'EMP-EDO');
        $outsiderRequest = $this->makePendingRequest($ctx, $outsider, $type, 1);

        $teamRequest = $this->makePendingRequest($ctx, $employee, $type, 2);

        $this->actingAs($manager)
            ->get(route('leave.edit', $outsiderRequest))
            ->assertForbidden();
        $this->actingAs($manager)
            ->put(route('leave.update', $outsiderRequest), [
                'leave_type_id' => $type->id,
                'start_date' => '2026-07-01',
                'end_date' => '2026-07-02',
            ])
            ->assertForbidden();

        $this->actingAs($manager)
            ->get(route('leave.edit', $teamRequest))
            ->assertOk();

        $this->actingAs($manager)
            ->from(route('leave.show', $teamRequest))
            ->put(route('leave.update', $teamRequest), [
                'leave_type_id' => $type->id,
                'start_date' => '2026-06-23',
                'end_date' => '2026-06-25',
            ])
            ->assertRedirect(route('leave.show', $teamRequest));

        $fresh = $teamRequest->fresh();
        $this->assertSame('2026-06-23', $fresh->start_date->toDateString());
        $this->assertSame(3, $fresh->days);
        $this->assertSame(1, $outsiderRequest->fresh()->days);
    }

    public function test_unpaid_leave_edits_never_touch_balances(): void
    {
        $ctx = $this->makeOrganization('Edit11');
        [$employee, , , $admin] = $this->bootLeave($ctx);
        $service = app(LeaveService::class);
        $this->actingAs($admin);

        $unpaid = LeaveType::withoutGlobalScopes()
            ->where('organization_id', $ctx['organization']->id)
            ->where('code', 'UNPAID')
            ->firstOrFail();

        $request = $this->makePendingRequest($ctx, $employee, $unpaid, 2);
        $service->approve($request, $admin->id);
        $this->assertSame(0, $this->leaveStamps($employee) - 2);

        $this->actingAs($admin)
            ->put(route('leave.update', $request), [
                'leave_type_id' => $unpaid->id,
                'start_date' => '2026-06-22',
                'end_date' => '2026-06-25',
            ])
            ->assertRedirect(route('leave.show', $request));

        $fresh = $request->fresh();
        $this->assertSame(LeaveRequest::APPROVED, $fresh->status);
        $this->assertSame(4, $fresh->days);

        $balance = LeaveBalance::withoutGlobalScopes()
            ->where('organization_id', $ctx['organization']->id)
            ->where('employee_id', $employee->id)
            ->where('leave_type_id', $unpaid->id)
            ->first();
        $this->assertSame(0, $balance?->used_days);
        $this->assertSame(4, $this->leaveStamps($employee));
    }

    public function test_edit_rejects_weekend_only_ranges(): void
    {
        $ctx = $this->makeOrganization('Edit12');
        [$employee, $type, , $admin] = $this->bootLeave($ctx);

        $request = $this->makePendingRequest($ctx, $employee, $type, 2);

        // Sat 27 Jun – Sun 28 Jun 2026: no working days.
        $this->actingAs($admin)
            ->from(route('leave.show', $request))
            ->put(route('leave.update', $request), [
                'leave_type_id' => $type->id,
                'start_date' => '2026-06-27',
                'end_date' => '2026-06-28',
            ])
            ->assertRedirect(route('leave.show', $request))
            ->assertSessionHasErrors('end_date');

        $this->assertSame(2, $request->fresh()->days);
    }

    public function test_leave_requests_from_another_organization_cannot_be_edited(): void
    {
        $ctx = $this->makeOrganization('Edit13');
        [$employee, $type, , $admin] = $this->bootLeave($ctx);
        $request = $this->makePendingRequest($ctx, $employee, $type, 2);

        $ctxB = $this->makeOrganization('Edit13B');
        $adminB = $this->makeUser($ctxB['organization'], 'company_admin', $ctxB['roles']);

        $this->actingAs($adminB)
            ->get(route('leave.edit', $request))
            ->assertNotFound();

        $this->actingAs($adminB)
            ->put(route('leave.update', $request), [
                'leave_type_id' => $type->id,
                'start_date' => '2026-06-22',
                'end_date' => '2026-06-24',
            ])
            ->assertNotFound();

        $this->assertSame(2, $request->fresh()->days);
    }
}
