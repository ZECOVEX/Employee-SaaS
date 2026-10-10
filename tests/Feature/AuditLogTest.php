<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Services\AuditLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesOrganizations;
use Tests\TestCase;

class AuditLogTest extends TestCase
{
    use CreatesOrganizations, RefreshDatabase;

    public function test_platform_status_toggle_lands_in_the_target_tenants_trail(): void
    {
        ['organization' => $target] = $this->makeOrganization('Target Co');
        ['organization' => $home, 'roles' => $homeRoles] = $this->makeOrganization('Home Co');
        $platform = $this->makeUser($home, 'company_admin', $homeRoles, ['is_platform_admin' => true]);

        $this->actingAs($platform)
            ->patch(route('platform.organizations.status', $target))
            ->assertRedirect();

        $log = AuditLog::withoutGlobalScopes()
            ->where('action', 'organization.status_changed')
            ->firstOrFail();

        // The resource's organization wins over the actor's own tenant (§24).
        $this->assertSame($target->id, $log->organization_id);
        $this->assertSame('Organization', $log->resource_type);
        $this->assertSame($target->id, (int) $log->resource_id);
        $this->assertSame('active', $log->old_value['status']);
        $this->assertSame('suspended', $log->new_value['status']);
    }

    public function test_user_role_change_audit_carries_role_slugs(): void
    {
        ['organization' => $org, 'roles' => $roles] = $this->makeOrganization();
        $admin = $this->makeUser($org, 'company_admin', $roles);
        $target = $this->makeUser($org, 'employee', $roles);

        $this->actingAs($admin)
            ->put(route('users.update', $target), [
                'name' => $target->name,
                'email' => $target->email,
                'role_id' => $roles['hr']->id,
            ])
            ->assertRedirect(route('users.index'));

        $log = AuditLog::withoutGlobalScopes()
            ->where('action', 'user.updated')
            ->firstOrFail();

        $this->assertSame($org->id, $log->organization_id);
        $this->assertSame('User', $log->resource_type);
        $this->assertSame($target->id, (int) $log->resource_id);
        // Readable slug diff — not ids (A6).
        $this->assertSame(['employee'], $log->old_value['roles']);
        $this->assertSame(['hr'], $log->new_value['roles']);
    }

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
