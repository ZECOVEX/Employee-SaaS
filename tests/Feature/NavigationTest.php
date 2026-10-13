<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesOrganizations;
use Tests\TestCase;

class NavigationTest extends TestCase
{
    use CreatesOrganizations;
    use RefreshDatabase;

    public function test_admin_nav_groups_secondary_sections_into_dropdown_subnavs(): void
    {
        ['organization' => $org, 'roles' => $roles] = $this->makeOrganization();
        $admin = $this->makeUser($org, 'company_admin', $roles);

        $response = $this->actingAs($admin)->get(route('dashboard.admin'));

        $response->assertOk()
            ->assertSee('People')
            ->assertSee('Payroll')
            ->assertSee(route('employees.index'))
            ->assertSee(route('attendance.index'))
            ->assertSee(route('leave.index'))
            ->assertSee(route('overtime.index'))
            ->assertSee(route('salary.index'))
            ->assertSee(route('reports.attendance'))
            ->assertSee(route('settings.edit'));
    }

    public function test_employee_nav_shows_primary_items_and_hides_admin_sections(): void
    {
        ['organization' => $org, 'roles' => $roles] = $this->makeOrganization();
        $user = $this->makeUser($org, 'employee', $roles);
        $this->makeEmployee($org, $user, 'EMP-NAV');

        $response = $this->actingAs($user)->get(route('dashboard.employee'));

        $response->assertOk()
            ->assertSee('My Attendance')
            ->assertSee('Leave')
            ->assertSee('Statistics')
            ->assertSee(route('notifications.index'))
            ->assertDontSee(route('employees.index'))
            ->assertDontSee(route('users.index'))
            ->assertDontSee(route('settings.edit'))
            ->assertDontSee(route('reports.attendance'));
    }

    public function test_manager_nav_keeps_team_sections_and_drops_admin_only_ones(): void
    {
        ['organization' => $org, 'roles' => $roles] = $this->makeOrganization();
        $manager = $this->makeUser($org, 'manager', $roles);

        $response = $this->actingAs($manager)->get(route('attendance.index'));

        $response->assertOk()
            ->assertSee(route('attendance.index'))
            ->assertSee(route('leave.index'))
            ->assertDontSee(route('users.index'))
            ->assertDontSee(route('settings.edit'))
            ->assertDontSee(route('billing.index'));
    }
}
