<?php

namespace Tests\Feature;

use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Services\LeaveService;
use App\Services\WorkforceDefaults;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesOrganizations;
use Tests\TestCase;

class LeaveTest extends TestCase
{
    use CreatesOrganizations;
    use RefreshDatabase;

    /**
     * @return array{0: \App\Models\Employee, 1: LeaveType, 2: \App\Models\User}
     */
    private function bootLeave(array $ctx, string $employeeName = 'Employee User'): array
    {
        app(WorkforceDefaults::class)->apply($ctx['organization']);

        $user = $this->makeUser($ctx['organization'], 'employee', $ctx['roles'], ['name' => $employeeName]);
        $employee = $this->makeEmployee($ctx['organization'], $user, 'EMP-L1');

        $leaveType = LeaveType::withoutGlobalScopes()
            ->where('organization_id', $ctx['organization']->id)
            ->where('code', 'ANNUAL')
            ->firstOrFail();

        app(LeaveService::class)->ensureBalances($employee);

        return [$employee, $leaveType, $user];
    }

    private function makePendingRequest(array $ctx, $employee, LeaveType $type, int $days, Carbon $start): LeaveRequest
    {
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

    public function test_employee_can_request_leave_with_weekday_count(): void
    {
        $ctx = $this->makeOrganization('LeaveCo1');
        app(WorkforceDefaults::class)->apply($ctx['organization']);
        $user = $this->makeUser($ctx['organization'], 'employee', $ctx['roles']);
        $this->makeEmployee($ctx['organization'], $user, 'EMP-L0');

        $type = LeaveType::withoutGlobalScopes()
            ->where('organization_id', $ctx['organization']->id)
            ->where('code', 'ANNUAL')
            ->firstOrFail();

        $start = now()->addWeek()->startOfDay();
        $end = $start->copy()->addDays(4);
        $expectedDays = app(LeaveService::class)->workingDays($start, $end);

        $this->actingAs($user)
            ->post(route('leave.store'), [
                'leave_type_id' => $type->id,
                'start_date' => $start->toDateString(),
                'end_date' => $end->toDateString(),
                'reason' => 'Vacation',
            ])
            ->assertRedirect(route('leave.index'));

        $this->assertDatabaseHas('leave_requests', [
            'organization_id' => $ctx['organization']->id,
            'employee_id' => \App\Models\Employee::withoutGlobalScopes()
                ->where('organization_id', $ctx['organization']->id)
                ->firstOrFail()
                ->id,
            'status' => LeaveRequest::PENDING,
            'days' => $expectedDays,
        ]);
    }

    public function test_store_rejects_range_exceeding_balance(): void
    {
        $ctx = $this->makeOrganization('LeaveCo1b');
        [$employee, $type, $user] = $this->bootLeave($ctx);

        // Shrink the balance so a two-week request no longer fits.
        $balance = LeaveBalance::withoutGlobalScopes()
            ->where('organization_id', $ctx['organization']->id)
            ->where('employee_id', $employee->id)
            ->where('leave_type_id', $type->id)
            ->firstOrFail();
        $balance->update(['total_days' => 2]);

        $start = now()->addWeek()->startOfDay();

        $this->actingAs($user)
            ->post(route('leave.store'), [
                'leave_type_id' => $type->id,
                'start_date' => $start->toDateString(),
                'end_date' => $start->copy()->addDays(9)->toDateString(),
                'reason' => 'Too long',
            ])
            ->assertSessionHasErrors('leave_type_id');

        $this->assertDatabaseMissing('leave_requests', [
            'organization_id' => $ctx['organization']->id,
            'status' => LeaveRequest::PENDING,
            'reason' => 'Too long',
        ]);
    }

    public function test_approve_deducts_balance_and_blocks_insufficient_days(): void
    {
        $ctx = $this->makeOrganization('LeaveCo2');
        [$employee, $type] = $this->bootLeave($ctx);
        $admin = $this->makeUser($ctx['organization'], 'company_admin', $ctx['roles']);
        $this->actingAs($admin);

        $service = app(LeaveService::class);

        // Shrink balance to 1 day so a 5-day request is impossible.
        $balance = LeaveBalance::withoutGlobalScopes()
            ->where('organization_id', $ctx['organization']->id)
            ->where('employee_id', $employee->id)
            ->where('leave_type_id', $type->id)
            ->firstOrFail();
        $balance->update(['total_days' => 1]);

        $request = $this->makePendingRequest($ctx, $employee, $type, 5, now()->addDays(2));

        try {
            $service->approve($request, $admin->id);
            $this->fail('Expected RuntimeException for insufficient balance.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Insufficient balance', $e->getMessage());
        }

        $this->assertSame(LeaveRequest::PENDING, $request->fresh()->status);

        // Shrink the request to fit within the 1-day balance.
        $request->update(['days' => 1, 'end_date' => $request->start_date->toDateString()]);
        $service->approve($request, $admin->id);

        $this->assertSame(LeaveRequest::APPROVED, $request->fresh()->status);

        $balance->refresh();
        $this->assertSame(1, $balance->used_days);
        $this->assertSame(0, $balance->remainingDays());
    }

    public function test_reject_keeps_balance_untouched(): void
    {
        $ctx = $this->makeOrganization('LeaveCo3');
        [$employee, $type] = $this->bootLeave($ctx);
        $admin = $this->makeUser($ctx['organization'], 'company_admin', $ctx['roles']);
        $this->actingAs($admin);

        $service = app(LeaveService::class);
        $request = $this->makePendingRequest($ctx, $employee, $type, 1, now()->addDay());

        $before = LeaveBalance::withoutGlobalScopes()
            ->where('organization_id', $ctx['organization']->id)
            ->where('employee_id', $employee->id)
            ->where('leave_type_id', $type->id)
            ->firstOrFail();

        $service->reject($request, $admin->id, 'Busy season');

        $this->assertSame(LeaveRequest::REJECTED, $request->fresh()->status);
        $this->assertSame('Busy season', $request->fresh()->review_note);

        $after = LeaveBalance::withoutGlobalScopes()
            ->where('organization_id', $ctx['organization']->id)
            ->where('employee_id', $employee->id)
            ->where('leave_type_id', $type->id)
            ->firstOrFail();

        $this->assertSame($before->used_days, $after->used_days);
    }

    public function test_employee_can_cancel_own_pending_request(): void
    {
        $ctx = $this->makeOrganization('LeaveCo4');
        [$employee, $type, $user] = $this->bootLeave($ctx);

        $request = $this->makePendingRequest($ctx, $employee, $type, 1, now()->addDays(3));

        $this->actingAs($user)
            ->post(route('leave.cancel', $request))
            ->assertRedirect();

        $this->assertSame(LeaveRequest::CANCELLED, $request->fresh()->status);
    }

    public function test_employee_cannot_cancel_someone_elses_request(): void
    {
        $ctx = $this->makeOrganization('LeaveCo4b');
        [$employee, $type] = $this->bootLeave($ctx);
        $other = $this->makeUser($ctx['organization'], 'employee', $ctx['roles']);

        $request = $this->makePendingRequest($ctx, $employee, $type, 1, now()->addDays(3));

        $this->actingAs($other)
            ->post(route('leave.cancel', $request))
            ->assertForbidden();

        $this->assertSame(LeaveRequest::PENDING, $request->fresh()->status);
    }

    public function test_leave_index_is_tenant_isolated(): void
    {
        $ctxA = $this->makeOrganization('LeaveCoA');
        $ctxB = $this->makeOrganization('LeaveCoB');
        $adminA = $this->makeUser($ctxA['organization'], 'company_admin', $ctxA['roles']);

        // The leave row renders the employee's name, so name it distinctly.
        [$employee, $type] = $this->bootLeave($ctxA, 'Zed Quark');
        $this->makePendingRequest($ctxA, $employee, $type, 1, now()->addDay());

        $this->actingAs($adminA)
            ->get(route('leave.index'))
            ->assertOk()
            ->assertSee('Zed Quark');

        $adminB = $this->makeUser($ctxB['organization'], 'company_admin', $ctxB['roles']);
        $this->actingAs($adminB)
            ->get(route('leave.index'))
            ->assertOk()
            ->assertDontSee('Zed Quark');
    }

    public function test_employee_sees_only_own_requests_in_index(): void
    {
        $ctx = $this->makeOrganization('LeaveCoC');
        [$employee, $type, $ownerUser] = $this->bootLeave($ctx, 'Own Requests Person');
        $this->makePendingRequest($ctx, $employee, $type, 1, now()->addDay());

        // A second employee with their own pending request.
        $otherUser = $this->makeUser($ctx['organization'], 'employee', $ctx['roles'], ['name' => 'Other Person']);
        $otherEmployee = $this->makeEmployee($ctx['organization'], $otherUser, 'EMP-L2');
        $this->makePendingRequest($ctx, $otherEmployee, $type, 1, now()->addDay());

        $this->actingAs($ownerUser)
            ->get(route('leave.index'))
            ->assertOk()
            ->assertSee('Own Requests Person')
            ->assertDontSee('Other Person');
    }

    public function test_employee_cannot_approve_without_permission(): void
    {
        $ctx = $this->makeOrganization('LeaveCoD');
        [$employee, $type, $user] = $this->bootLeave($ctx);

        $request = $this->makePendingRequest($ctx, $employee, $type, 1, now()->addDay());

        $this->actingAs($user)
            ->post(route('leave.approve', $request))
            ->assertForbidden();

        $this->assertSame(LeaveRequest::PENDING, $request->fresh()->status);
    }
}
