<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\Position;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesOrganizations;
use Tests\TestCase;

class PositionsTest extends TestCase
{
    use CreatesOrganizations, RefreshDatabase;

    public function test_positions_page_requires_the_positions_manage_permission(): void
    {
        ['organization' => $org, 'roles' => $roles] = $this->makeOrganization();

        $hr = $this->makeUser($org, 'hr', $roles);
        $employee = $this->makeUser($org, 'employee', $roles);

        $this->actingAs($hr)->get(route('positions.index'))->assertForbidden();
        $this->actingAs($employee)->get(route('positions.index'))->assertForbidden();
        $this->actingAs($hr)->get(route('positions.create'))->assertForbidden();

        $admin = $this->makeUser($org, 'company_admin', $roles);
        $this->actingAs($admin)->get(route('positions.index'))->assertOk();
        $this->actingAs($admin)->get(route('positions.create'))->assertOk();
    }

    public function test_navigation_shows_the_positions_link_only_for_positions_managers(): void
    {
        ['organization' => $org, 'roles' => $roles] = $this->makeOrganization();
        $admin = $this->makeUser($org, 'company_admin', $roles);
        $hr = $this->makeUser($org, 'hr', $roles);

        $this->actingAs($admin)
            ->get(route('dashboard.admin'))
            ->assertOk()
            ->assertSee('Positions');

        $this->actingAs($hr)
            ->get(route('dashboard.admin'))
            ->assertOk()
            ->assertDontSee('Positions');
    }

    public function test_company_admin_can_create_update_and_delete_positions(): void
    {
        ['organization' => $org, 'roles' => $roles] = $this->makeOrganization();
        $admin = $this->makeUser($org, 'company_admin', $roles);
        $department = $this->makeDepartment($org);

        $this->actingAs($admin)
            ->post(route('positions.store'), [
                'title' => 'QA Engineer',
                'department_id' => $department->id,
            ])
            ->assertRedirect(route('positions.index'));

        $position = Position::where('title', 'QA Engineer')->firstOrFail();
        $this->assertSame($org->id, $position->organization_id);
        $this->assertSame($department->id, $position->department_id);
        $this->assertTrue(
            AuditLog::where('organization_id', $org->id)
                ->where('action', 'position.created')
                ->where('actor_user_id', $admin->id)
                ->exists()
        );

        $this->actingAs($admin)
            ->get(route('positions.edit', $position))
            ->assertOk()
            ->assertSee('QA Engineer');

        $this->actingAs($admin)
            ->put(route('positions.update', $position), [
                'title' => 'Senior QA Engineer',
                'department_id' => '',
            ])
            ->assertRedirect(route('positions.index'));

        $position->refresh();
        $this->assertSame('Senior QA Engineer', $position->title);
        $this->assertNull($position->department_id);
        $this->assertTrue(
            AuditLog::where('organization_id', $org->id)
                ->where('action', 'position.updated')
                ->where('actor_user_id', $admin->id)
                ->exists()
        );

        $this->actingAs($admin)
            ->delete(route('positions.destroy', $position))
            ->assertRedirect(route('positions.index'));

        $this->assertNull(Position::find($position->id));
        $this->assertTrue(
            AuditLog::where('organization_id', $org->id)
                ->where('action', 'position.deleted')
                ->where('actor_user_id', $admin->id)
                ->exists()
        );
    }

    public function test_position_titles_are_unique_per_tenant_and_other_tenants_get_404(): void
    {
        ['organization' => $orgA, 'roles' => $rolesA] = $this->makeOrganization('Alpha Corp');
        $adminA = $this->makeUser($orgA, 'company_admin', $rolesA);

        ['organization' => $orgB, 'roles' => $rolesB] = $this->makeOrganization('Beta Corp');
        $adminB = $this->makeUser($orgB, 'company_admin', $rolesB);

        $this->actingAs($adminA)
            ->post(route('positions.store'), ['title' => 'Engineer'])
            ->assertRedirect(route('positions.index'));

        // The same title in a different tenant is allowed.
        $this->actingAs($adminB)
            ->post(route('positions.store'), ['title' => 'Engineer'])
            ->assertRedirect(route('positions.index'));

        $this->assertSame(2, Position::withoutGlobalScopes()->where('title', 'Engineer')->count());

        // Duplicate inside the same tenant is rejected.
        $this->actingAs($adminA)
            ->post(route('positions.store'), ['title' => 'Engineer'])
            ->assertSessionHasErrors('title');

        // Tenant B cannot view or edit tenant A's position (404).
        $positionA = Position::withoutGlobalScopes()
            ->where('organization_id', $orgA->id)
            ->firstOrFail();

        $this->actingAs($adminB)->get(route('positions.edit', $positionA))->assertNotFound();
        $this->actingAs($adminB)
            ->put(route('positions.update', $positionA), ['title' => 'Hijacked'])
            ->assertNotFound();
        $this->actingAs($adminB)
            ->delete(route('positions.destroy', $positionA))
            ->assertNotFound();
    }

    public function test_department_must_belong_to_the_same_tenant(): void
    {
        ['organization' => $orgA, 'roles' => $rolesA] = $this->makeOrganization('Alpha Corp');
        $adminA = $this->makeUser($orgA, 'company_admin', $rolesA);

        ['organization' => $orgB, 'roles' => $rolesB] = $this->makeOrganization('Beta Corp');
        $this->makeUser($orgB, 'company_admin', $rolesB);
        $departmentB = $this->makeDepartment($orgB, 'Beta Engineering');

        $this->actingAs($adminA)
            ->post(route('positions.store'), [
                'title' => 'Designer',
                'department_id' => $departmentB->id,
            ])
            ->assertSessionHasErrors('department_id');

        $this->assertSame(0, Position::withoutGlobalScopes()->where('title', 'Designer')->count());
    }

    public function test_created_positions_feed_the_employee_form_and_stay_on_the_profile(): void
    {
        ['organization' => $org, 'roles' => $roles] = $this->makeOrganization();
        $admin = $this->makeUser($org, 'company_admin', $roles);
        $department = $this->makeDepartment($org);

        $this->actingAs($admin)
            ->post(route('positions.store'), [
                'title' => 'Platform Engineer',
                'department_id' => $department->id,
            ])
            ->assertRedirect(route('positions.index'));

        $position = Position::where('title', 'Platform Engineer')->firstOrFail();

        // The employee form offers the new position.
        $this->actingAs($admin)
            ->get(route('employees.create'))
            ->assertOk()
            ->assertSee('Platform Engineer');

        // Saving an employee with it persists the link.
        $this->actingAs($admin)->post(route('employees.store'), [
            'name' => 'Pos Person',
            'email' => 'pos.person@acme.test',
            'password' => 'password',
            'password_confirmation' => 'password',
            'employee_code' => 'EMP-POS1',
            'department_id' => $department->id,
            'position_id' => $position->id,
            'status' => 'active',
            'employment_type' => 'full_time',
        ])->assertRedirect();

        $employee = Employee::where('employee_code', 'EMP-POS1')->firstOrFail();
        $this->assertSame($position->id, $employee->position_id);

        // And the profile shows it.
        $this->actingAs($admin)
            ->get(route('employees.show', $employee))
            ->assertOk()
            ->assertSee('Platform Engineer');
    }
}
