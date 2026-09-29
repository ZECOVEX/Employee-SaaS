<?php

namespace Tests\Feature;

use App\Services\AuditLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesOrganizations;
use Tests\TestCase;

class AuditLogTest extends TestCase
{
    use CreatesOrganizations, RefreshDatabase;

    public function test_audit_index_filters_by_action_and_resource_type(): void
    {
        ['organization' => $org, 'roles' => $roles] = $this->makeOrganization();
        $admin = $this->makeUser($org, 'company_admin', $roles);
        $department = $this->makeDepartment($org);

        $this->actingAs($admin);
        app(AuditLogger::class)->log('employee.created');
        app(AuditLogger::class)->log('department.created', $department);

        $this->get(route('audit-logs.index'))
            ->assertOk()
            ->assertSee('font-mono text-xs">employee.created</td>', false)
            ->assertSee('font-mono text-xs">department.created</td>', false);

        $this->get(route('audit-logs.index', ['action' => 'employee.created']))
            ->assertOk()
            ->assertSee('font-mono text-xs">employee.created</td>', false)
            ->assertDontSee('font-mono text-xs">department.created</td>', false);

        $this->get(route('audit-logs.index', ['resource_type' => 'Department']))
            ->assertOk()
            ->assertSee('Department #');
    }

    public function test_audit_logs_are_tenant_scoped(): void
    {
        ['organization' => $orgA, 'roles' => $rolesA] = $this->makeOrganization();
        $adminA = $this->makeUser($orgA, 'company_admin', $rolesA);
        $departmentA = $this->makeDepartment($orgA);

        ['organization' => $orgB, 'roles' => $rolesB] = $this->makeOrganization();
        $adminB = $this->makeUser($orgB, 'company_admin', $rolesB);
        $departmentB = $this->makeDepartment($orgB, 'Other Tenant Dept');

        $this->actingAs($adminA);
        app(AuditLogger::class)->log('department.created', $departmentA);

        $this->actingAs($adminB);
        app(AuditLogger::class)->log('department.created', $departmentB);

        $this->get(route('audit-logs.index'))
            ->assertOk()
            ->assertSee("Department #{$departmentB->id}")
            ->assertDontSee("Department #{$departmentA->id}");

        $this->actingAs($adminA);
        $this->get(route('audit-logs.index'))
            ->assertOk()
            ->assertSee("Department #{$departmentA->id}")
            ->assertDontSee("Department #{$departmentB->id}");
    }
}
