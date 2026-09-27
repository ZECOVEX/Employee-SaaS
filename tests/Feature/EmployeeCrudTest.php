<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesOrganizations;
use Tests\TestCase;

class EmployeeCrudTest extends TestCase
{
    use CreatesOrganizations, RefreshDatabase;

    public function test_admin_can_create_employee_and_audit_is_written(): void
    {
        ['organization' => $org, 'roles' => $roles] = $this->makeOrganization();
        $admin = $this->makeUser($org, 'company_admin', $roles);
        $department = $this->makeDepartment($org);

        $response = $this->actingAs($admin)->post(route('employees.store'), [
            'name' => 'Jane Doe',
            'email' => 'jane@acme.test',
            'password' => 'password',
            'password_confirmation' => 'password',
            'employee_code' => 'EMP-042',
            'department_id' => $department->id,
            'status' => 'active',
            'employment_type' => 'full_time',
        ]);

        $employee = Employee::where('employee_code', 'EMP-042')->firstOrFail();
        $response->assertRedirect(route('employees.show', $employee));

        $this->assertDatabaseHas('users', [
            'organization_id' => $org->id,
            'email' => 'jane@acme.test',
        ]);

        $this->assertTrue(
            AuditLog::where('organization_id', $org->id)
                ->where('action', 'employee.created')
                ->where('actor_user_id', $admin->id)
                ->exists()
        );
    }

    public function test_admin_can_update_employee(): void
    {
        ['organization' => $org, 'roles' => $roles] = $this->makeOrganization();
        $admin = $this->makeUser($org, 'company_admin', $roles);
        $user = $this->makeUser($org, 'employee', $roles, ['email' => 'e@acme.test']);
        $employee = $this->makeEmployee($org, $user, 'EMP-007');

        $this->actingAs($admin)
            ->put(route('employees.update', $employee), [
                'name' => 'Renamed Person',
                'email' => 'e@acme.test',
                'status' => 'inactive',
                'employment_type' => 'full_time',
            ])
            ->assertRedirect(route('employees.show', $employee));

        $this->assertSame('inactive', $employee->fresh()->status);
        $this->assertSame('Renamed Person', $user->fresh()->name);

        $this->assertTrue(
            AuditLog::where('action', 'employee.updated')
                ->where('organization_id', $org->id)
                ->exists()
        );
    }

    public function test_duplicate_employee_code_rejected_within_tenant(): void
    {
        ['organization' => $org, 'roles' => $roles] = $this->makeOrganization();
        $admin = $this->makeUser($org, 'company_admin', $roles);
        $user = $this->makeUser($org, 'employee', $roles);
        $this->makeEmployee($org, $user, 'EMP-DUP');

        $this->actingAs($admin)
            ->post(route('employees.store'), [
                'name' => 'Dup',
                'email' => 'dup@acme.test',
                'password' => 'password',
                'password_confirmation' => 'password',
                'employee_code' => 'EMP-DUP',
                'status' => 'active',
                'employment_type' => 'full_time',
            ])
            ->assertSessionHasErrors('employee_code');
    }

    public function test_hr_can_create_department(): void
    {
        ['organization' => $org, 'roles' => $roles] = $this->makeOrganization();
        $hr = $this->makeUser($org, 'hr', $roles);

        $this->actingAs($hr)
            ->post(route('departments.store'), [
                'name' => 'Finance',
                'code' => 'FIN',
            ])
            ->assertRedirect(route('departments.index'));

        $this->assertDatabaseHas('departments', [
            'organization_id' => $org->id,
            'name' => 'Finance',
        ]);
    }
}
