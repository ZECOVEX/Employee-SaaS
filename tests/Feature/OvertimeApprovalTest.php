<?php

namespace Tests\Feature;

use App\Models\AttendanceEvent;
use App\Models\DailyAttendance;
use App\Models\Employee;
use App\Models\OvertimeApproval;
use App\Models\OvertimePolicy;
use App\Models\OvertimeRecord;
use App\Models\SalaryRecord;
use App\Models\User;
use App\Services\AttendanceService;
use App\Services\WorkforceDefaults;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesOrganizations;
use Tests\TestCase;

/**
 * Phase 5 M2 — §73-E approval workflow: queue scoping, approve/reject/adjust
 * with the audit trail, self-approval ban and decision notifications.
 */
class OvertimeApprovalTest extends TestCase
{
    use CreatesOrganizations;
    use RefreshDatabase;

    private array $ctx;

    private User $admin;

    private User $hr;

    private User $manager;

    private User $teamUser;

    private User $soloUser;

    private Employee $teamMember;

    private Employee $soloMember;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-09-29 23:00:00');

        $this->ctx = $this->makeOrganization('OtFlow '.uniqid());
        app(WorkforceDefaults::class)->apply($this->ctx['organization']);
        $roles = $this->ctx['roles'];

        $this->admin = $this->makeUser($this->ctx['organization'], 'company_admin', $roles, ['name' => 'Ada Admin']);
        $this->hr = $this->makeUser($this->ctx['organization'], 'hr', $roles, ['name' => 'Hana HR']);
        $this->manager = $this->makeUser($this->ctx['organization'], 'manager', $roles, ['name' => 'Max Manager']);

        $this->teamUser = $this->makeUser($this->ctx['organization'], 'employee', $roles, ['name' => 'Team Amy']);
        $this->teamMember = $this->makeEmployee($this->ctx['organization'], $this->teamUser, 'EMP-T1');
        $this->teamMember->update(['manager_id' => $this->manager->id]);

        $this->soloUser = $this->makeUser($this->ctx['organization'], 'employee', $roles, ['name' => 'Solo Ben']);
        $this->soloMember = $this->makeEmployee($this->ctx['organization'], $this->soloUser, 'EMP-S1');
    }

    public function test_queue_requires_permission_and_scopes_by_role(): void
    {
        $this->pendingRecord($this->teamMember, '2026-09-29');
        $this->pendingRecord($this->soloMember, '2026-09-28');

        $this->actingAs($this->teamUser)
            ->get(route('overtime.queue'))
            ->assertForbidden();

        $this->actingAs($this->manager)
            ->get(route('overtime.queue'))
            ->assertOk()
            ->assertSee('Team Amy')
            ->assertDontSee('Solo Ben')
            ->assertSee('direct reports');

        $this->actingAs($this->hr)
            ->get(route('overtime.queue'))
            ->assertOk()
            ->assertSee('Team Amy')
            ->assertSee('Solo Ben');

        $this->actingAs($this->admin)
            ->get(route('overtime.queue'))
            ->assertOk()
            ->assertSee('Team Amy')
            ->assertSee('Solo Ben');
    }

    public function test_approve_updates_status_mirror_trail_audit_and_notification(): void
    {
        $record = $this->pendingRecord($this->teamMember, '2026-09-29');
        $this->assertSame(OvertimeRecord::STATUS_PENDING, $record->status);
        $this->assertSame(0, $this->dailyOvertime($this->teamMember, '2026-09-29'));

        $this->actingAs($this->admin)
            ->post(route('overtime.approve', $record))
            ->assertRedirect();

        $record->refresh();
        $this->assertSame(OvertimeRecord::STATUS_APPROVED, $record->status);
        $this->assertSame(75, $this->dailyOvertime($this->teamMember, '2026-09-29'));

        $this->assertDatabaseHas('overtime_approvals', [
            'organization_id' => $this->ctx['organization']->id,
            'overtime_record_id' => $record->id,
            'approver_user_id' => $this->admin->id,
            'decision' => OvertimeApproval::APPROVED,
            'minutes_before' => 75,
            'minutes_after' => 75,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'organization_id' => $this->ctx['organization']->id,
            'action' => 'overtime.approved',
        ]);

        $titles = $this->teamUser->notifications()->get()
            ->map(fn ($n) => $n->data['title'])
            ->all();
        $this->assertContains('Overtime approved', $titles);
    }

    public function test_approve_only_works_on_pending_records(): void
    {
        // Default policy auto-approves, so this record is not pending.
        $record = $this->seedOvertime($this->teamMember, '2026-09-29');
        $this->assertSame(OvertimeRecord::STATUS_AUTO_APPROVED, $record->status);

        $this->actingAs($this->admin)
            ->post(route('overtime.approve', $record))
            ->assertSessionHasErrors('record');

        $this->assertSame(OvertimeRecord::STATUS_AUTO_APPROVED, $record->fresh()->status);
    }

    public function test_reject_requires_a_reason_and_notifies_the_employee(): void
    {
        $record = $this->pendingRecord($this->teamMember, '2026-09-29');

        $this->actingAs($this->admin)
            ->post(route('overtime.reject', $record))
            ->assertSessionHasErrors('reason');

        $this->assertSame(OvertimeRecord::STATUS_PENDING, $record->fresh()->status);

        $this->actingAs($this->admin)
            ->post(route('overtime.reject', $record), ['reason' => 'Project deadline missed'])
            ->assertRedirect();

        $record->refresh();
        $this->assertSame(OvertimeRecord::STATUS_REJECTED, $record->status);
        $this->assertSame(0, $this->dailyOvertime($this->teamMember, '2026-09-29'));

        $this->assertDatabaseHas('overtime_approvals', [
            'overtime_record_id' => $record->id,
            'decision' => OvertimeApproval::REJECTED,
            'minutes_before' => 75,
            'minutes_after' => 0,
            'reason' => 'Project deadline missed',
        ]);

        $rejected = $this->teamUser->notifications()->get()
            ->first(fn ($n) => $n->data['title'] === 'Overtime rejected');
        $this->assertNotNull($rejected);
        $this->assertStringContainsString('Project deadline missed', $rejected->data['body']);
    }

    public function test_nobody_may_decide_their_own_overtime(): void
    {
        $managerEmployee = $this->makeEmployee($this->ctx['organization'], $this->manager, 'EMP-M1');
        $record = $this->pendingRecord($managerEmployee, '2026-09-29');

        $this->actingAs($this->manager)
            ->post(route('overtime.approve', $record))
            ->assertForbidden();

        $this->assertSame(OvertimeRecord::STATUS_PENDING, $record->fresh()->status);
    }

    public function test_manager_cannot_decide_out_of_team_but_hr_can(): void
    {
        $record = $this->pendingRecord($this->soloMember, '2026-09-28');

        $this->actingAs($this->manager)
            ->post(route('overtime.approve', $record))
            ->assertForbidden();

        $this->assertSame(OvertimeRecord::STATUS_PENDING, $record->fresh()->status);

        $this->actingAs($this->hr)
            ->post(route('overtime.approve', $record))
            ->assertRedirect();

        $this->assertSame(OvertimeRecord::STATUS_APPROVED, $record->fresh()->status);
    }

    public function test_adjust_changes_minutes_audits_and_can_grant_manually(): void
    {
        $this->salaryFor($this->teamMember);
        $record = $this->pendingRecord($this->teamMember, '2026-09-29');

        $this->actingAs($this->admin)
            ->post(route('overtime.adjust', $record), [
                'minutes' => 90,
                'reason' => 'Agreed with employee',
            ])
            ->assertRedirect();

        $record->refresh();
        $this->assertSame(90, $record->countable_minutes);
        $this->assertSame(OvertimeRecord::STATUS_APPROVED, $record->status); // manual decision locks it
        $this->assertSame(90, $this->dailyOvertime($this->teamMember, '2026-09-29'));
        // 90/60 × 272.7273 × 1.5 = 613.64.
        $this->assertEqualsWithDelta(613.64, $record->estimated_pay, 0.01);

        $this->assertDatabaseHas('overtime_approvals', [
            'overtime_record_id' => $record->id,
            'decision' => OvertimeApproval::ADJUSTED,
            'minutes_before' => 75,
            'minutes_after' => 90,
            'reason' => 'Agreed with employee',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'organization_id' => $this->ctx['organization']->id,
            'action' => 'overtime.adjusted',
        ]);

        // A manual grant turns a below-threshold (NONE) day into approved.
        $none = $this->seedOvertime($this->teamMember, '2026-09-28', out: '18:10');
        $this->assertSame(OvertimeRecord::STATUS_NONE, $none->status);

        $this->actingAs($this->admin)
            ->post(route('overtime.adjust', $none), ['minutes' => 30])
            ->assertRedirect();

        $none->refresh();
        $this->assertSame(OvertimeRecord::STATUS_APPROVED, $none->status);
        $this->assertSame(30, $none->countable_minutes);
        $this->assertSame(30, $this->dailyOvertime($this->teamMember, '2026-09-28'));

        $this->actingAs($this->admin)
            ->post(route('overtime.adjust', $none), ['minutes' => 5000])
            ->assertSessionHasErrors('minutes');

        // The earlier adjustment survives re-derivation (locked by decision).
        $this->assertSame(90, $record->fresh()->countable_minutes);
    }

    public function test_records_from_another_organization_return_404(): void
    {
        $record = $this->pendingRecord($this->teamMember, '2026-09-29');

        $otherCtx = $this->makeOrganization('OtherCo '.uniqid());
        $otherAdmin = $this->makeUser($otherCtx['organization'], 'company_admin', $otherCtx['roles']);

        $this->actingAs($otherAdmin)
            ->post(route('overtime.approve', $record))
            ->assertNotFound();

        $this->assertSame(OvertimeRecord::STATUS_PENDING, $record->fresh()->status);
    }

    /** MANAGER approval mode so derived overtime waits for a decision. */
    private function pendingRecord(Employee $employee, string $date): OvertimeRecord
    {
        if (! OvertimePolicy::withoutGlobalScopes()
            ->where('organization_id', $employee->organization_id)
            ->exists()) {
            OvertimePolicy::withoutGlobalScopes()->create([
                'organization_id' => $employee->organization_id,
                ...OvertimePolicy::defaults(),
                'approval_mode' => OvertimePolicy::APPROVE_MANAGER,
            ]);
        }

        return $this->seedOvertime($employee, $date);
    }

    private function seedOvertime(Employee $employee, string $date, string $out = '19:15'): OvertimeRecord
    {
        foreach ([
            [AttendanceEvent::CHECK_IN, '09:00'],
            [AttendanceEvent::CHECK_OUT, '13:00'],
            [AttendanceEvent::CHECK_IN, '14:00'],
            [AttendanceEvent::CHECK_OUT, $out],
        ] as [$type, $time]) {
            AttendanceEvent::withoutGlobalScopes()->create([
                'organization_id' => $employee->organization_id,
                'employee_id' => $employee->id,
                'event_type' => $type,
                'occurred_at' => Carbon::parse($date.' '.$time),
                'timezone' => 'UTC',
                'source' => 'manual',
                'notes' => 'seed',
                'created_by' => $employee->user_id,
            ]);
        }

        app(AttendanceService::class)->deriveDaily($employee, $date);

        return OvertimeRecord::withoutGlobalScopes()
            ->where('employee_id', $employee->id)
            ->where('work_date', $date)
            ->firstOrFail();
    }

    private function dailyOvertime(Employee $employee, string $date): int
    {
        return DailyAttendance::withoutGlobalScopes()
            ->where('employee_id', $employee->id)
            ->whereDate('date', $date)
            ->firstOrFail()
            ->overtime_minutes;
    }

    private function salaryFor(Employee $employee): void
    {
        $amounts = SalaryRecord::computeAmounts(48000, 0, 0, 0);
        SalaryRecord::withoutGlobalScopes()->create([
            'organization_id' => $employee->organization_id,
            'employee_id' => $employee->id,
            'basic_salary' => 48000,
            'allowances' => 0,
            'bonus' => 0,
            'deductions' => 0,
            ...$amounts,
            'effective_from' => '2026-01-01',
        ]);
    }
}
