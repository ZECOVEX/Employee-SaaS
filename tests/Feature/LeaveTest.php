<?php

namespace Tests\Feature;

use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Services\LeaveService;
use App\Services\WorkforceDefaults;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesOrganizations;
use Tests\TestCase;

class LeaveTest extends TestCase
{
    use CreatesOrganizations;
    use RefreshDatabase;

    private function bootLeave($org): array
    {
        WorkforceDefaults::apply($org);

        $employee = $this->makeEmployee($org, 'EMP-L1', 'leave1@test.com');
        $leaveType = LeaveType::withoutGlobalScopes()
            ->where('organization_id', $org->id)
            ->where('code', 'ANNUAL')
            ->firstOrFail();

        return [$employee, $leaveType];
    }

    public function test_owner_can_request_leave_and_balance_is_reserved(): void
    {
        $org = $this->makeOrganization('LeaveCo1');
        $owner = $org->users()->where('email', $this->ownerEmail())->firstOrFail();
        $owner->syncRoles(['owner']);

        $this->actingAs($owner)
            ->post(route('leave.store'), [
                'leave_type_id' => LeaveType::withoutGlobalScopes()->where('organization_id', $org->id)->where('code', 'ANNUAL')->firstOrFail()->id,
                'start_date' => now()->addWeek()->toDateString(),
                'end_date' => now()->addWeek()->addDays(2)->toDateString(),
                'reason' => 'Vacation',
            ])
            ->assertRedirect(route('leave.index'));

        $this->assertDatabaseHas('leave_requests', [
            'organization_id' => $org->id,
            'status' => 'PENDING',
            'days' => 3,
        ]);
    }

    public function test_approve_deducts_balance_and_reject_blocks_insufficient_days(): void
    {
        $org = $this->makeOrganization('LeaveCo2');
        $owner = $org->users()->where('email', $this->ownerEmail())->firstOrFail();
        $owner->syncRoles(['owner']);
        [$employee, $leaveType] = $this->bootLeave($org);

        $service = app(LeaveService::class);

        // Shrink balance to 1 day so a 5-day request is impossible.
        $balance = LeaveBalance::withoutGlobalScopes()
            ->where('organization_id', $org->id)
            ->where('employee_id', $employee->id)
            ->where('leave_type_id', $leaveType->id)
            ->firstOrFail();
        $balance->update(['total_days' => 1]);

        $request = new LeaveRequest([
            'start_date' => now()->addDays(2)->toDateString(),
            'end_date' => now()->addDays(6)->toDateString(),
            'days' => 5,
            'reason' => 'Too long',
            'status' => LeaveRequest::STATUS_PENDING,
        ]);
        $request->organization()->associate($org);
        $request->employee()->associate($employee);
        $request->leaveType()->associate($leaveType);
        $request->save();

        $this->assertFalse($service->approve($request, $owner));
        $this->assertSame(LeaveRequest::STATUS_PENDING, $request->fresh()->status);

        // Shrink the request to fit within the 1-day balance.
        $request->update(['days' => 1, 'end_date' => $request->start_date->toDateString()]);
        $this->assertTrue($service->approve($request, $owner));
        $this->assertSame(LeaveRequest::STATUS_APPROVED, $request->fresh()->status);

        $balance->refresh();
        $this->assertSame(0, $balance->remainingDays());
    }

    public function test_reject_keeps_balance_untouched(): void
    {
        $org = $this->makeOrganization('LeaveCo3');
        $owner = $org->users()->where('email', $this->ownerEmail())->firstOrFail();
        $owner->syncRoles(['owner']);
        [$employee, $leaveType] = $this->bootLeave($org);

        $service = app(LeaveService::class);
        $request = new LeaveRequest([
            'start_date' => now()->addDay()->toDateString(),
            'end_date' => now()->addDay()->toDateString(),
            'days' => 1,
            'reason' => 'Sick day',
            'status' => LeaveRequest::STATUS_PENDING,
        ]);
        $request->organization()->associate($org);
        $request->employee()->associate($employee);
        $request->leaveType()->associate($leaveType);
        $request->save();

        $before = LeaveBalance::withoutGlobalScopes()
            ->where('organization_id', $org->id)
            ->where('employee_id', $employee->id)
            ->where('leave_type_id', $leaveType->id)
            ->firstOrFail()->remainingDays();

        $this->assertTrue($service->reject($request, $owner, 'Nope'));
        $this->assertSame(LeaveRequest::STATUS_REJECTED, $request->fresh()->status);

        $after = LeaveBalance::withoutGlobalScopes()
            ->where('organization_id', $org->id)
            ->where('employee_id', $employee->id)
            ->where('leave_type_id', $leaveType->id)
            ->firstOrFail()->remainingDays();

        $this->assertSame($before, $after);
    }

    public function test_employee_can_cancel_own_pending_request(): void
    {
        $org = $this->makeOrganization('LeaveCo4');
        [$employee, $leaveType] = $this->bootLeave($org);
        $employeeUser = $employee->user;

        $service = app(LeaveService::class);
        $request = new LeaveRequest([
            'start_date' => now()->addDays(3)->toDateString(),
            'end_date' => now()->addDays(3)->toDateString(),
            'days' => 1,
            'reason' => 'Changed plan',
            'status' => LeaveRequest::STATUS_PENDING,
        ]);
        $request->organization()->associate($org);
        $request->employee()->associate($employee);
        $request->leaveType()->associate($leaveType);
        $request->save();

        $this->actingAs($employeeUser)
            ->post(route('leave.cancel', $request))
            ->assertRedirect(route('leave.index'));

        $this->assertSame(LeaveRequest::STATUS_CANCELLED, $request->fresh()->status);
    }

    public function test_leave_index_is_tenant_isolated(): void
    {
        $orgA = $this->makeOrganization('LeaveCoA');
        $orgB = $this->makeOrganization('LeaveCoB');
        $ownerA = $orgA->users()->where('email', $this->ownerEmail())->firstOrFail();
        $ownerA->syncRoles(['owner']);
        $this->bootLeave($orgA);

        $leaveTypeA = LeaveType::withoutGlobalScopes()->where('organization_id', $orgA->id)->where('code', 'SICK')->firstOrFail();
        $employeeA = \App\Models\Employee::withoutGlobalScopes()->where('organization_id', $orgA->id)->firstOrFail();

        $request = new LeaveRequest([
            'start_date' => now()->addDay()->toDateString(),
            'end_date' => now()->addDay()->toDateString(),
            'days' => 1,
            'reason' => 'A only',
            'status' => LeaveRequest::STATUS_PENDING,
        ]);
        $request->organization()->associate($orgA);
        $request->employee()->associate($employeeA);
        $request->leaveType()->associate($leaveTypeA);
        $request->save();

        $this->actingAs($ownerA)
            ->get(route('leave.index'))
            ->assertOk()
            ->assertSee('A only');

        $ownerB = $orgB->users()->where('email', $this->ownerEmail())->firstOrFail();
        $ownerB->syncRoles(['owner']);
        $this->actingAs($ownerB)
            ->get(route('leave.index'))
            ->assertOk()
            ->assertDontSee('A only');
    }

    public function test_employee_cannot_approve_without_permission(): void
    {
        $org = $this->makeOrganization('LeaveCoC');
        [$employee, $leaveType] = $this->bootLeave($org);

        $service = app(LeaveService::class);
        $request = new LeaveRequest([
            'start_date' => now()->addDay()->toDateString(),
            'end_date' => now()->addDay()->toDateString(),
            'days' => 1,
            'reason' => 'x',
            'status' => LeaveRequest::STATUS_PENDING,
        ]);
        $request->organization()->associate($org);
        $request->employee()->associate($employee);
        $request->leaveType()->associate($leaveType);
        $request->save();

        $this->actingAs($employee->user)
            ->post(route('leave.approve', $request))
            ->assertForbidden();
    }
}
