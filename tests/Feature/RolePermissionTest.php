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

    public function test_delegated_role_editor_cannot_grant_beyond_their_own_permissions(): void
    {
        ['organization' => $org, 'roles' => $roles] = $this->makeOrganization();
        $admin = $this->makeUser($org, 'company_admin', $roles);
        $hr = $this->makeUser($org, 'hr', $roles);
        $manager = $roles['manager'];

        // Delegate role editing to HR — a legitimate admin action.
        $this->actingAs($admin)
            ->put(route('roles.update', $roles['hr']), [
                'permissions' => [
                    ...$roles['hr']->load('permissions')->permissions->pluck('key')->all(),
                    'users.manage',
                ],
            ])
            ->assertRedirect(route('roles.index'));

        $hr->unsetRelation('roles');
        $before = $manager->load('permissions')->permissions->pluck('key')->sort()->values()->all();

        // HR may keep existing keys and grant self-scope keys HR does not
        // hold (own-team / own-data)…
        $this->actingAs($hr)
            ->put(route('roles.update', $manager), [
                'permissions' => [...$before, 'salary.view_own'],
            ])
            ->assertRedirect(route('roles.index'));

        // …but may NOT grant a permission HR does not hold.
        $this->actingAs($hr)
            ->from(route('roles.edit', $manager))
            ->put(route('roles.update', $manager), [
                'permissions' => [...$before, 'salary.view_own', 'audit.view'],
            ])
            ->assertSessionHasErrors('permissions');

        $keys = $manager->refresh()->permissions->pluck('key')->sort()->values()->all();
        $this->assertContains('salary.view_own', $keys);
        $this->assertNotContains('audit.view', $keys);

        // Removals are always allowed.
        $this->actingAs($hr)
            ->put(route('roles.update', $manager), ['permissions' => ['org.view']])
            ->assertRedirect(route('roles.index'));

        $this->assertSame(
            ['org.view'],
            $manager->refresh()->permissions->pluck('key')->sort()->values()->all(),
        );
    }
}
