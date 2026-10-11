<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AttendanceEvent;
use App\Models\AuditLog;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\NfcCard;
use App\Models\Payslip;
use App\Models\Position;
use App\Models\SalaryRecord;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesOrganizations;
use Tests\TestCase;

/**
 * §62 — end-to-end journey: one full employee lifecycle from admin
 * onboarding through attendance, leave, payroll and the audit trail,
 * stitched together across the web UI routes and the NFC punch API.
 */
class EndToEndJourneyTest extends TestCase
{
    use CreatesOrganizations, RefreshDatabase;

    public function test_full_employee_lifecycle_from_onboarding_to_payslip(): void
    {
        $ctx = $this->makeOrganization('Journey Co');
        $admin = $this->makeUser($ctx['organization'], 'company_admin', $ctx['roles']);

        // ── 1. Admin sets up the org chart ───────────────────────────
        $this->actingAs($admin)
            ->post(route('departments.store'), ['name' => 'Engineering', 'code' => 'ENG'])
            ->assertRedirect(route('departments.index'));
        $department = $ctx['organization']->departments()->where('name', 'Engineering')->firstOrFail();

        $this->actingAs($admin)
            ->post(route('positions.store'), [
                'title' => 'Software Engineer',
                'department_id' => $department->id,
            ])
            ->assertRedirect(route('positions.index'));
        $position = Position::withoutGlobalScopes()->where('title', 'Software Engineer')->firstOrFail();

        // ── 2. Admin creates the employee (user + profile + role) ────
        $this->actingAs($admin)
            ->post(route('employees.store'), [
                'name' => 'Journey Employee',
                'email' => 'journey.employee@journey.test',
                'password' => 'Str0ng!Passw0rd',
                'password_confirmation' => 'Str0ng!Passw0rd',
                'employee_code' => 'EMP-J1',
                'department_id' => $department->id,
                'position_id' => $position->id,
                'status' => 'active',
                'employment_type' => 'full_time',
                'joining_date' => now()->toDateString(),
            ])
            ->assertRedirect();

        $employeeUser = User::withoutGlobalScopes()
            ->where('email', 'journey.employee@journey.test')
            ->firstOrFail();
        $employee = $ctx['organization']->employees()->where('user_id', $employeeUser->id)->firstOrFail();

        $this->assertSame($department->id, $employee->department_id);
        $this->assertSame($position->id, $employee->position_id);
        $this->assertTrue($employeeUser->must_change_password);
        $this->assertTrue($employeeUser->hasRole('employee'));

        // ── 3. NFC punch in / out through a registered terminal ──────
        $this->actingAs($admin)
            ->post(route('terminals.store'), ['name' => 'TERM-JOURNEY', 'location' => 'HQ'])
            ->assertRedirect(route('terminals.index'));

        $plainKey = Str::after(session('status'), 'again: ');

        $card = NfcCard::create([
            'organization_id' => $ctx['organization']->id,
            'card_token' => 'tok-journey',
            'status' => 'available',
        ]);
        $this->actingAs($admin)
            ->post(route('nfc-cards.assign', $card), ['employee_id' => $employee->id])
            ->assertRedirect();

        $this->withHeaders(['X-Terminal-Key' => $plainKey])
            ->postJson('/api/attendance/nfc/punch', ['card_token' => 'tok-journey'])
            ->assertOk()
            ->assertJsonPath('action', 'check_in')
            ->assertJsonPath('employee.code', 'EMP-J1');

        $this->travel(2)->hours();

        $this->withHeaders(['X-Terminal-Key' => $plainKey])
            ->postJson('/api/attendance/nfc/punch', ['card_token' => 'tok-journey'])
            ->assertOk()
            ->assertJsonPath('action', 'check_out');

        $this->travelBack();

        $this->assertSame(
            2,
            AttendanceEvent::withoutGlobalScopes()
                ->where('organization_id', $ctx['organization']->id)
                ->where('employee_id', $employee->id)
                ->count(),
        );

        // ── 4. Employee requests leave; admin approves ───────────────
        $leaveType = LeaveType::create([
            'organization_id' => $ctx['organization']->id,
            'name' => 'Vacation',
            'code' => 'VAC',
            'is_active' => true,
            'default_days_per_year' => 0,
        ]);

        $this->actingAs($employeeUser)
            ->post(route('leave.store'), [
                'leave_type_id' => $leaveType->id,
                'start_date' => now()->addWeek()->toDateString(),
                'end_date' => now()->addWeek()->addDays(2)->toDateString(),
                'reason' => 'Family trip',
            ])
            ->assertRedirect();

        $leave = LeaveRequest::withoutGlobalScopes()
            ->where('employee_id', $employee->id)
            ->firstOrFail();
        $this->assertSame('PENDING', $leave->status);

        $this->actingAs($admin)
            ->post(route('leave.approve', $leave))
            ->assertRedirect();
        $this->assertSame('APPROVED', $leave->fresh()->status);

        // ── 5. Admin records salary and finalizes a payslip ──────────
        $this->actingAs($admin)
            ->post(route('salary.store', $employee), [
                'basic_salary' => 50000,
                'allowances' => 5000,
                'effective_from' => now()->startOfMonth()->toDateString(),
            ])
            ->assertRedirect();

        $this->assertSame(
            1,
            SalaryRecord::withoutGlobalScopes()->where('employee_id', $employee->id)->count(),
        );

        $this->actingAs($admin)
            ->post(route('payslips.store', $employee), [
                'period' => now()->format('Y-m'),
            ])
            ->assertRedirect();

        $payslip = Payslip::withoutGlobalScopes()
            ->where('employee_id', $employee->id)
            ->where('period', now()->format('Y-m'))
            ->firstOrFail();
        $this->assertNotNull($payslip->net_salary);

        // ── 6. Employee sees their own data; salary stays gated ──────
        $this->actingAs($employeeUser)
            ->get('/profile')
            ->assertOk()
            ->assertSee('Journey Employee');

        $this->actingAs($employeeUser)
            ->get(route('salary.show', $employee))
            ->assertOk();

        // A second employee cannot see the first employee's salary.
        $otherUser = $this->makeUser($ctx['organization'], 'employee', $ctx['roles']);
        $this->makeEmployee($ctx['organization'], $otherUser, 'EMP-J2');

        $this->actingAs($otherUser)
            ->get(route('salary.show', $employee))
            ->assertForbidden();

        // ── 7. Audit trail covers the whole journey ──────────────────
        $actions = AuditLog::withoutGlobalScopes()
            ->where('organization_id', $ctx['organization']->id)
            ->pluck('action');

        foreach ([
            'department.created',
            'position.created',
            'employee.created',
            'terminal.created',
            'nfc.assigned',
            'leave.requested',
            'salary.created',
            'salary.payslip_finalized',
        ] as $expected) {
            $this->assertTrue($actions->contains($expected), "Missing audit action: $expected");
        }
    }
}
