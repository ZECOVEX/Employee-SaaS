<?php

namespace Tests\Feature;

use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesOrganizations;
use Tests\TestCase;

class RolePermissionTest extends TestCase
{
    use CreatesOrganizations, RefreshDatabase;

    public function test_roles_page_requires_users_manage(): void
    {
        ['organization' => $org, 'roles' => $roles] = $this->makeOrganization();
        $hr = $this->makeUser($org, 'hr', $roles);
        $admin = $this->makeUser($org, 'company_admin', $roles);

        $this->actingAs($hr)->get(route('roles.index'))->assertForbidden();
        $this->actingAs($admin)->get(route('roles.index'))->assertOk();
    }

    public function test_updating_a_role_syncs_permissions_and_audits(): void
    {
        ['organization' => $org, 'roles' => $roles] = $this->makeOrganization();
        $admin = $this->makeUser($org, 'company_admin', $roles);
        $manager = $roles['manager'];

        $this->actingAs($admin)
            ->put(route('roles.update', $manager), [
                'permissions' => ['org.view', 'employees.view_team', 'leave.approve'],
            ])
            ->assertRedirect(route('roles.index'));

        $keys = $manager->refresh()->permissions->pluck('key')->sort()->values()->all();
        $this->assertSame(['employees.view_team', 'leave.approve', 'org.view'], $keys);

        $this->assertDatabaseHas('audit_logs', ['action' => 'role.permissions_updated']);
    }

    public function test_update_rejects_unknown_permission_keys(): void
    {
        ['organization' => $org, 'roles' => $roles] = $this->makeOrganization();
        $admin = $this->makeUser($org, 'company_admin', $roles);

        $this->actingAs($admin)
            ->from(route('roles.edit', $roles['manager']))
            ->put(route('roles.update', $roles['manager']), [
                'permissions' => ['org.view', 'not.a.real.key'],
            ])
            ->assertSessionHasErrors();
    }

    public function test_company_admin_role_is_locked(): void
    {
        ['organization' => $org, 'roles' => $roles] = $this->makeOrganization();
        $admin = $this->makeUser($org, 'company_admin', $roles);
        $locked = $roles['company_admin'];

        $this->actingAs($admin)->get(route('roles.edit', $locked))->assertForbidden();
        $this->actingAs($admin)
            ->put(route('roles.update', $locked), ['permissions' => ['org.view']])
            ->assertForbidden();

        // Unchanged.
        $this->assertTrue($locked->refresh()->permissions->pluck('key')->contains('users.manage'));
    }

    public function test_role_changes_take_effect_for_members(): void
    {
        ['organization' => $org, 'roles' => $roles] = $this->makeOrganization();
        $admin = $this->makeUser($org, 'company_admin', $roles);
        $manager = $this->makeUser($org, 'manager', $roles);

        // Strip reports.view from the manager role.
        $this->actingAs($admin)
            ->put(route('roles.update', $roles['manager']), [
                'permissions' => ['org.view', 'employees.view_team'],
            ]);

        $this->actingAs($manager)
            ->get(route('reports.attendance'))
            ->assertForbidden();
    }

    public function test_roles_index_lists_all_roles(): void
    {
        ['organization' => $org, 'roles' => $roles] = $this->makeOrganization();
        $admin = $this->makeUser($org, 'company_admin', $roles);

        $response = $this->actingAs($admin)->get(route('roles.index'));
        $response->assertOk();

        foreach (array_keys($roles) as $slug) {
            $response->assertSee(Role::where('organization_id', $org->id)->where('slug', $slug)->first()->name);
        }
    }
}
