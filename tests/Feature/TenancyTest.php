<?php

namespace Tests\Feature;

use App\Models\Department;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesOrganizations;
use Tests\TestCase;

class TenancyTest extends TestCase
{
    use CreatesOrganizations, RefreshDatabase;

    public function test_user_cannot_view_another_tenants_department(): void
    {
        ['organization' => $orgA, 'roles' => $rolesA] = $this->makeOrganization('Org A');
        ['organization' => $orgB, 'roles' => $rolesB] = $this->makeOrganization('Org B');

        $adminA = $this->makeUser($orgA, 'company_admin', $rolesA);
        $departmentB = $this->makeDepartment($orgB, 'Secret Dept');

        $this->actingAs($adminA)
            ->get(route('departments.edit', $departmentB))
            ->assertNotFound();
    }

    public function test_user_cannot_view_another_tenants_employee(): void
    {
        ['organization' => $orgA, 'roles' => $rolesA] = $this->makeOrganization('Org A');
        ['organization' => $orgB, 'roles' => $rolesB] = $this->makeOrganization('Org B');

        $adminA = $this->makeUser($orgA, 'company_admin', $rolesA);
        $userB = $this->makeUser($orgB, 'employee', $rolesB, ['email' => 'b@orgb.test']);
        $employeeB = $this->makeEmployee($orgB, $userB, 'EMP-B');

        $this->actingAs($adminA)
            ->get(route('employees.show', $employeeB))
            ->assertNotFound();
    }

    public function test_employee_index_only_lists_current_tenant(): void
    {
        ['organization' => $orgA, 'roles' => $rolesA] = $this->makeOrganization('Org A');
        ['organization' => $orgB, 'roles' => $rolesB] = $this->makeOrganization('Org B');

        $adminA = $this->makeUser($orgA, 'company_admin', $rolesA);
        $userB = $this->makeUser($orgB, 'employee', $rolesB);
        $this->makeEmployee($orgB, $userB, 'EMP-FOREIGN');

        $response = $this->actingAs($adminA)->get(route('employees.index'));
        $response->assertOk();
        $response->assertDontSee('EMP-FOREIGN');
    }

    public function test_cannot_update_another_tenants_department(): void
    {
        ['organization' => $orgA, 'roles' => $rolesA] = $this->makeOrganization('Org A');
        ['organization' => $orgB, 'roles' => $rolesB] = $this->makeOrganization('Org B');

        $adminA = $this->makeUser($orgA, 'company_admin', $rolesA);
        $departmentB = $this->makeDepartment($orgB, 'Hacked');

        $this->actingAs($adminA)
            ->put(route('departments.update', $departmentB), ['name' => 'Pwned'])
            ->assertNotFound();

        $this->assertSame('Hacked', $departmentB->fresh()->name);
    }

    public function test_unauthenticated_user_sees_no_tenant_rows(): void
    {
        ['organization' => $org] = $this->makeOrganization();
        $this->makeDepartment($org, 'Hidden');

        $this->assertGuest();
        $this->assertSame(0, Department::count());
    }
}
