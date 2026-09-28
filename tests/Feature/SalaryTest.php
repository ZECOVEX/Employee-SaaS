<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\Organization;
use App\Models\SalaryRecord;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesOrganizations;
use Tests\TestCase;

class SalaryTest extends TestCase
{
    use CreatesOrganizations;
    use RefreshDatabase;

    /**
     * @return array{0: array{organization: Organization, roles: array}, 1: User, 2: User, 3: Employee}
     */
    private function setupSalaryOrg(string $name): array
    {
        $ctx = $this->makeOrganization($name);
        $admin = $this->makeUser($ctx['organization'], 'company_admin', $ctx['roles']);
        $user = $this->makeUser($ctx['organization'], 'employee', $ctx['roles']);
        $employee = $this->makeEmployee($ctx['organization'], $user, 'EMP-S1');

        return [$ctx, $admin, $user, $employee];
    }

    public function test_admin_can_add_effective_dated_salary_records(): void
    {
        [$ctx, $admin,, $employee] = $this->setupSalaryOrg('SalCo');

        $this->actingAs($admin)
            ->post(route('salary.store', $employee), [
                'basic_salary' => 30000,
                'allowances' => 2000,
                'bonus' => 1000,
                'deductions' => 500,
                'effective_from' => '2026-01-01',
                'notes' => 'annual raise',
            ])
            ->assertRedirect(route('salary.show', $employee))
            ->assertSessionHas('status');

        $first = SalaryRecord::withoutGlobalScopes()->where('employee_id', $employee->id)->firstOrFail();
        $this->assertSame(30000.0, $first->basic_salary);
        $this->assertSame(33000.0, $first->gross_salary); // 30,000 + 2,000 + 1,000
        $this->assertSame(32500.0, $first->net_salary);   // 33,000 − 500
        $this->assertSame('2026-01-01', $first->effective_from->toDateString());
        $this->assertNull($first->effective_to);
        $this->assertSame('annual raise', $first->notes);

        // Raise from Jul 1 closes the old range the day before — history intact.
        $this->actingAs($admin)
            ->post(route('salary.store', $employee), [
                'basic_salary' => 35000,
                'effective_from' => '2026-07-01',
            ])
            ->assertRedirect(route('salary.show', $employee));

        $first->refresh();
        $this->assertSame('2026-06-30', $first->effective_to->toDateString());
        $this->assertSame(30000.0, $first->basic_salary);

        // The right version is effective for each date (§28 historical reports).
        $this->assertSame(
            30000.0,
            SalaryRecord::effectiveFor($ctx['organization']->id, $employee->id, '2026-06-15')->basic_salary,
        );
        $this->assertSame(
            35000.0,
            SalaryRecord::effectiveFor($ctx['organization']->id, $employee->id, '2026-07-15')->basic_salary,
        );
        $this->assertNull(SalaryRecord::effectiveFor($ctx['organization']->id, $employee->id, '2025-12-31'));

        $this->assertSame(
            2,
            AuditLog::withoutGlobalScopes()
                ->where('organization_id', $ctx['organization']->id)
                ->where('action', 'salary.created')
                ->count(),
        );
    }

    public function test_two_records_cannot_start_on_the_same_date(): void
    {
        [, $admin,, $employee] = $this->setupSalaryOrg('SalDup');

        $payload = ['basic_salary' => 30000, 'effective_from' => '2026-01-01'];

        $this->actingAs($admin)->post(route('salary.store', $employee), $payload)
            ->assertRedirect(route('salary.show', $employee))
            ->assertSessionHasNoErrors();

        $this->actingAs($admin)
            ->post(route('salary.store', $employee), ['basic_salary' => 31000, 'effective_from' => '2026-01-01'])
            ->assertSessionHasErrors('effective_from');

        $this->assertSame(1, SalaryRecord::withoutGlobalScopes()->where('employee_id', $employee->id)->count());
    }

    public function test_employee_can_view_own_salary_but_not_others(): void
    {
        [, $admin, $user, $employee] = $this->setupSalaryOrg('SalSelf');

        $otherUser = $this->makeUser($employee->organization, 'employee', null, ['email' => 'other-'.uniqid().'@example.test']);
        $other = $this->makeEmployee($employee->organization, $otherUser, 'EMP-S2');

        $this->actingAs($admin)
            ->post(route('salary.store', $employee), ['basic_salary' => 30000, 'effective_from' => '2026-01-01']);

        $this->actingAs($user)->get(route('salary.show', $employee))->assertOk();
        $this->actingAs($user)->get(route('salary.show', $other))->assertForbidden();
        $this->actingAs($user)->get(route('salary.index'))->assertForbidden();
        $this->actingAs($user)
            ->post(route('salary.store', $employee), ['basic_salary' => 1, 'effective_from' => '2026-02-01'])
            ->assertForbidden();
    }

    public function test_hr_can_view_salary_but_not_add_records(): void
    {
        [$ctx,, , $employee] = $this->setupSalaryOrg('SalHr');
        $hr = $this->makeUser($ctx['organization'], 'hr', $ctx['roles']);

        $this->actingAs($hr)
            ->post(route('salary.store', $employee), ['basic_salary' => 1, 'effective_from' => '2026-01-01'])
            ->assertForbidden();

        $this->actingAs($hr)->get(route('salary.show', $employee))->assertOk();
    }

    public function test_salary_routes_are_tenant_isolated(): void
    {
        [$ctx,, $user, $employee] = $this->setupSalaryOrg('SalIsoA');
        $ctxB = $this->makeOrganization('SalIsoB');
        $adminB = $this->makeUser($ctxB['organization'], 'company_admin', $ctxB['roles']);

        $this->actingAs($user)
            ->post(route('salary.store', $employee), ['basic_salary' => 30000, 'effective_from' => '2026-01-01'])
            ->assertForbidden(); // salary.view_own does not include create rights

        $this->actingAs($adminB)->get(route('salary.show', $employee))->assertNotFound();
    }

    public function test_salary_index_lists_current_records(): void
    {
        [$ctx, $admin,, $employee] = $this->setupSalaryOrg('SalIdx');

        $this->actingAs($admin)
            ->post(route('salary.store', $employee), ['basic_salary' => 30000, 'effective_from' => '2026-01-01']);

        $this->actingAs($admin)
            ->get(route('salary.index'))
            ->assertOk()
            ->assertSee('EMP-S1')
            ->assertSee('30,000.00');
    }
}
