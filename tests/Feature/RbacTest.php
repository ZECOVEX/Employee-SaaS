<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesOrganizations;
use Tests\TestCase;

class RbacTest extends TestCase
{
    use CreatesOrganizations, RefreshDatabase;

    public function test_employee_cannot_access_admin_dashboard(): void
    {
        ['organization' => $org, 'roles' => $roles] = $this->makeOrganization();
        $employee = $this->makeUser($org, 'employee', $roles);

        $this->actingAs($employee)
            ->get(route('dashboard.admin'))
            ->assertForbidden();
    }

    public function test_employee_cannot_list_employees(): void
    {
        ['organization' => $org, 'roles' => $roles] = $this->makeOrganization();
        $employee = $this->makeUser($org, 'employee', $roles);

        $this->actingAs($employee)
            ->get(route('employees.index'))
            ->assertForbidden();
    }

    public function test_employee_cannot_create_department(): void
    {
        ['organization' => $org, 'roles' => $roles] = $this->makeOrganization();
        $employee = $this->makeUser($org, 'employee', $roles);

        $this->actingAs($employee)
            ->post(route('departments.store'), ['name' => 'Nope'])
            ->assertForbidden();
    }

    public function test_employee_cannot_view_audit_logs(): void
    {
        ['organization' => $org, 'roles' => $roles] = $this->makeOrganization();
        $employee = $this->makeUser($org, 'employee', $roles);

        $this->actingAs($employee)
            ->get(route('audit-logs.index'))
            ->assertForbidden();
    }

    public function test_hr_can_manage_departments_but_not_users(): void
    {
        ['organization' => $org, 'roles' => $roles] = $this->makeOrganization();
        $hr = $this->makeUser($org, 'hr', $roles);

        $this->actingAs($hr)
            ->post(route('departments.store'), ['name' => 'People Ops'])
            ->assertRedirect(route('departments.index'));

        $this->actingAs($hr)
            ->get(route('users.create'))
            ->assertForbidden();
    }

    public function test_manager_cannot_create_employees(): void
    {
        ['organization' => $org, 'roles' => $roles] = $this->makeOrganization();
        $manager = $this->makeUser($org, 'manager', $roles);

        $this->actingAs($manager)
            ->get(route('employees.create'))
            ->assertForbidden();
    }

    public function test_company_admin_can_access_admin_dashboard(): void
    {
        ['organization' => $org, 'roles' => $roles] = $this->makeOrganization();
        $admin = $this->makeUser($org, 'company_admin', $roles);

        $this->actingAs($admin)
            ->get(route('dashboard.admin'))
            ->assertOk();
    }

    public function test_employee_redirected_to_their_dashboard(): void
    {
        ['organization' => $org, 'roles' => $roles] = $this->makeOrganization();
        $employee = $this->makeUser($org, 'employee', $roles);

        $this->actingAs($employee)
            ->get(route('dashboard'))
            ->assertRedirect(route('dashboard.employee'));
    }

    public function test_company_user_cannot_access_platform_area(): void
    {
        ['organization' => $org, 'roles' => $roles] = $this->makeOrganization();
        $admin = $this->makeUser($org, 'company_admin', $roles);

        $this->actingAs($admin)
            ->get(route('platform.organizations'))
            ->assertForbidden();
    }

    public function test_platform_admin_can_access_platform_area(): void
    {
        $platform = User::create([
            'organization_id' => null,
            'name' => 'Platform Root',
            'email' => 'root@platform.test',
            'password' => 'password',
            'email_verified_at' => now(),
            'is_platform_admin' => true,
        ]);

        $this->actingAs($platform)
            ->get(route('platform.organizations'))
            ->assertOk();
    }
}
