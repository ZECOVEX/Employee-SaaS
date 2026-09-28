<?php

namespace Tests\Feature;

use App\Models\AttendanceEvent;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\Organization;
use App\Models\Payslip;
use App\Models\User;
use App\Services\AttendanceService;
use App\Services\WorkforceDefaults;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesOrganizations;
use Tests\TestCase;

class PayslipTest extends TestCase
{
    use CreatesOrganizations;
    use RefreshDatabase;

    /**
     * Org + defaults + employee with a 30,000 + 2,000 salary record (from
     * 2026-01-01) and an admin who can finalize payslips.
     *
     * @return array{0: Organization, 1: User, 2: User, 3: Employee}
     */
    private function bootPayslip(string $name): array
    {
        $ctx = $this->makeOrganization($name);
        app(WorkforceDefaults::class)->apply($ctx['organization']);

        $admin = $this->makeUser($ctx['organization'], 'company_admin', $ctx['roles']);
        $user = $this->makeUser($ctx['organization'], 'employee', $ctx['roles']);
        $employee = $this->makeEmployee($ctx['organization'], $user, 'EMP-P1');

        $this->actingAs($admin)
            ->post(route('salary.store', $employee), [
                'basic_salary' => 30000,
                'allowances' => 2000,
                'effective_from' => '2026-01-01',
            ])
            ->assertRedirect(route('salary.show', $employee));

        return [$ctx['organization'], $admin, $user, $employee];
    }

    /**
     * Full-attendance month: every weekday gets 09:00 → 18:00 (PRESENT,
     * no deductions), so finalized numbers are clean.
     */
    private function punchMonth($employee, $user, string $month): void
    {
        $service = app(AttendanceService::class);
        $cursor = Carbon::createFromFormat('!Y-m-d', $month.'-01');
        $end = $cursor->copy()->endOfMonth();

        while ($cursor->lte($end)) {
            if (in_array((int) $cursor->dayOfWeekIso, [1, 2, 3, 4, 5], true)) {
                $service->recordManual($employee, AttendanceEvent::CHECK_IN, $cursor->copy()->setTime(9, 0), 'seed', $user->id);
                $service->recordManual($employee, AttendanceEvent::CHECK_OUT, $cursor->copy()->setTime(18, 0), 'seed', $user->id);
            }
            $cursor->addDay();
        }
    }

    public function test_admin_finalizes_a_payslip_for_a_completed_month(): void
    {
        $this->travelTo(Carbon::parse('2026-10-05 10:00:00'));
        [, $admin, $user, $employee] = $this->bootPayslip('PayDone');
        $this->punchMonth($employee, $user, '2026-09');

        $this->actingAs($admin)
            ->post(route('payslips.store', $employee), [
                'period' => '2026-09',
                'notes' => 'month-end payroll',
            ])
            ->assertRedirect(route('salary.show', $employee))
            ->assertSessionHas('status');

        $payslip = Payslip::withoutGlobalScopes()->where('employee_id', $employee->id)->firstOrFail();
        $this->assertSame('2026-09', $payslip->period);
        $this->assertSame(1, $payslip->revision);
        $this->assertNull($payslip->superseded_by);
        $this->assertSame(22, $payslip->scheduled_days);
        $this->assertSame(22, $payslip->completed_days);
        $this->assertSame(10560, $payslip->worked_minutes); // 22 × 480
        $this->assertEqualsWithDelta(0.0, $payslip->attendance_deduction_total, 0.001);
        $this->assertEqualsWithDelta(32000.0, $payslip->net_salary, 0.001);
        $this->assertSame($admin->id, $payslip->created_by);
        $this->assertCount(30, $payslip->snapshot['breakdown'] ?? []);
        $this->assertSame('month-end payroll', $payslip->notes);

        // Listed on the salary page as Final.
        $this->actingAs($admin)
            ->get(route('salary.show', $employee))
            ->assertOk()
            ->assertSee('Finalized payslips')
            ->assertSee('Sep 2026')
            ->assertSee('32,000.00');

        $this->assertSame(1, AuditLog::withoutGlobalScopes()
            ->where('organization_id', $payslip->organization_id)
            ->where('action', 'salary.payslip_finalized')
            ->count());
    }

    public function test_future_and_malformed_periods_and_duplicates_are_rejected(): void
    {
        $this->travelTo(Carbon::parse('2026-09-15 10:00:00'));
        [, $admin, $user, $employee] = $this->bootPayslip('PayRules');

        $this->actingAs($admin)
            ->post(route('payslips.store', $employee), ['period' => '2026-10'])
            ->assertSessionHasErrors('period');

        $this->actingAs($admin)
            ->post(route('payslips.store', $employee), ['period' => 'September'])
            ->assertSessionHasErrors('period');

        $this->assertSame(0, Payslip::withoutGlobalScopes()->count());

        // Current month can be finalized mid-run (labeled Estimated).
        $this->actingAs($admin)
            ->post(route('payslips.store', $employee), ['period' => '2026-09'])
            ->assertRedirect(route('salary.show', $employee));

        // Second attempt must revise instead of duplicating.
        $this->actingAs($admin)
            ->post(route('payslips.store', $employee), ['period' => '2026-09'])
            ->assertSessionHasErrors('period');

        $this->assertSame(1, Payslip::withoutGlobalScopes()->count());

        $this->actingAs($admin)
            ->get(route('salary.show', $employee))
            ->assertOk()
            ->assertSee('Estimated (mid-month)');
    }

    public function test_revising_creates_a_new_revision_and_keeps_history(): void
    {
        $this->travelTo(Carbon::parse('2026-10-05 10:00:00'));
        [, $admin, $user, $employee] = $this->bootPayslip('PayRevise');
        $this->punchMonth($employee, $user, '2026-09');

        $this->actingAs($admin)
            ->post(route('payslips.store', $employee), ['period' => '2026-09'])
            ->assertSessionHas('status');

        $first = Payslip::withoutGlobalScopes()->where('employee_id', $employee->id)->firstOrFail();
        $this->assertEqualsWithDelta(32000.0, $first->net_salary, 0.001);

        // Revise only recalculates — the superseded revision stays untouched.
        $this->actingAs($admin)
            ->put(route('payslips.revise', [$employee, $first]))
            ->assertRedirect(route('salary.show', $employee))
            ->assertSessionHas('status');

        $first->refresh();
        $this->assertNotNull($first->superseded_by);

        $active = Payslip::withoutGlobalScopes()
            ->where('employee_id', $employee->id)
            ->whereNull('superseded_by')
            ->firstOrFail();
        $this->assertSame(2, $active->revision);
        $this->assertNotSame($first->id, $active->id);
        $this->assertSame(2, Payslip::withoutGlobalScopes()->where('employee_id', $employee->id)->count());

        // Revising an already-superseded revision is rejected.
        $this->actingAs($admin)
            ->put(route('payslips.revise', [$employee, $first]))
            ->assertSessionHasErrors('payslip');

        // The payslip page shows both revisions with status badges.
        $this->actingAs($admin)
            ->get(route('payslips.show', [$employee, $active]))
            ->assertOk()
            ->assertSee('Revision history')
            ->assertSee('Superseded')
            ->assertSee('Active');

        $this->assertSame(1, AuditLog::withoutGlobalScopes()
            ->where('organization_id', $active->organization_id)
            ->where('action', 'salary.payslip_revised')
            ->count());
    }

    public function test_snapshot_is_frozen_when_salary_rules_or_records_change(): void
    {
        $this->travelTo(Carbon::parse('2026-10-05 10:00:00'));
        [, $admin, $user, $employee] = $this->bootPayslip('PayFreeze');
        $this->punchMonth($employee, $user, '2026-09');

        $this->actingAs($admin)
            ->post(route('payslips.store', $employee), ['period' => '2026-09']);

        $payslip = Payslip::withoutGlobalScopes()->where('employee_id', $employee->id)->firstOrFail();
        $this->assertEqualsWithDelta(32000.0, $payslip->net_salary, 0.001);

        // A raise + rule change after finalization must not move the payslip.
        $this->actingAs($admin)
            ->post(route('salary.store', $employee), [
                'basic_salary' => 35000,
                'effective_from' => '2026-09-01',
            ]);

        $this->actingAs($admin)
            ->put(route('settings.update'), [
                'name' => 'PayFreeze',
                'timezone' => 'UTC',
                'debounce_seconds' => 15,
                'time_format' => '24h',
                'max_deduction_percent' => 10,
            ]);

        $this->assertEqualsWithDelta(32000.0, $payslip->fresh()->net_salary, 0.001);

        // The salary page reflects the raise; the finalized payslip does not.
        $this->actingAs($admin)
            ->get(route('salary.show', $employee))
            ->assertOk()
            ->assertSee('35,000.00')  // current salary record
            ->assertSee('32,000.00'); // frozen payslip net

        // Revising picks up the new salary — both numbers stay visible.
        $this->actingAs($admin)
            ->put(route('payslips.revise', [$employee, $payslip]));

        $active = Payslip::withoutGlobalScopes()
            ->where('employee_id', $employee->id)
            ->whereNull('superseded_by')
            ->firstOrFail();
        $this->assertEqualsWithDelta(35000.0, $active->net_salary, 0.001);
        $this->assertEqualsWithDelta(32000.0, $payslip->fresh()->net_salary, 0.001);
    }

    public function test_employees_can_view_their_own_payslip_but_not_finalize(): void
    {
        $this->travelTo(Carbon::parse('2026-10-05 10:00:00'));
        [$org, $admin, $user, $employee] = $this->bootPayslip('PayAccess');
        $this->punchMonth($employee, $user, '2026-09');

        $this->actingAs($admin)
            ->post(route('payslips.store', $employee), ['period' => '2026-09']);

        $payslip = Payslip::withoutGlobalScopes()->where('employee_id', $employee->id)->firstOrFail();

        // Owner sees their own payslip; someone else's is 403.
        $this->actingAs($user)
            ->get(route('payslips.show', [$employee, $payslip]))
            ->assertOk()
            ->assertSee('Payslip — Sep 2026')
            ->assertSee('Print / Save PDF');

        $otherUser = $this->makeUser($org, 'employee', null, ['email' => 'other-'.uniqid().'@example.test']);
        $this->makeEmployee($org, $otherUser, 'EMP-P2');

        // Another employee opening this payslip is forbidden (no salary.view).
        $this->actingAs($otherUser)
            ->get(route('payslips.show', [$employee, $payslip]))
            ->assertForbidden();

        // Employees cannot finalize or revise (permission:salary.edit).
        $this->actingAs($user)
            ->post(route('payslips.store', $employee), ['period' => '2026-08'])
            ->assertForbidden();
        $this->actingAs($user)
            ->put(route('payslips.revise', [$employee, $payslip]))
            ->assertForbidden();

        // HR may view but not finalize.
        $hr = $this->makeUser($org, 'hr');
        $this->actingAs($hr)
            ->get(route('payslips.show', [$employee, $payslip]))
            ->assertOk();
        $this->actingAs($hr)
            ->post(route('payslips.store', $employee), ['period' => '2026-08'])
            ->assertForbidden();
    }

    public function test_payslip_routes_are_tenant_isolated(): void
    {
        $this->travelTo(Carbon::parse('2026-10-05 10:00:00'));
        [, $admin,, $employee] = $this->bootPayslip('PayIsoA');

        $this->actingAs($admin)
            ->post(route('payslips.store', $employee), ['period' => '2026-09']);
        $payslip = Payslip::withoutGlobalScopes()->where('employee_id', $employee->id)->firstOrFail();

        $ctxB = $this->makeOrganization('PayIsoB');
        $adminB = $this->makeUser($ctxB['organization'], 'company_admin', $ctxB['roles']);

        $this->actingAs($adminB)
            ->get(route('payslips.show', [$employee, $payslip]))
            ->assertNotFound();
        $this->actingAs($adminB)
            ->post(route('payslips.store', $employee), ['period' => '2026-08'])
            ->assertNotFound();
        $this->actingAs($adminB)
            ->put(route('payslips.revise', [$employee, $payslip]))
            ->assertNotFound();
    }
}
