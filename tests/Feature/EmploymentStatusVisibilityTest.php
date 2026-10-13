<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\User;
use App\Notifications\EmploymentStatusChanged;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Volt\Volt;
use Tests\Concerns\CreatesOrganizations;
use Tests\TestCase;

class EmploymentStatusVisibilityTest extends TestCase
{
    use CreatesOrganizations;
    use RefreshDatabase;

    /** @return array{0: User, 1: Employee} */
    private function makeEmployeeWithStatus(array $ctx, string $status, string $code = 'EMP-ST1'): array
    {
        $user = $this->makeUser($ctx['organization'], 'employee', $ctx['roles']);
        $employee = $this->makeEmployee($ctx['organization'], $user, $code);
        $employee->update(['status' => $status]);

        return [$user, $employee];
    }

    public function test_terminated_employee_can_still_log_in(): void
    {
        $ctx = $this->makeOrganization('TermLogin');
        [$user] = $this->makeEmployeeWithStatus($ctx, 'terminated');

        $component = Volt::test('pages.auth.login')
            ->set('form.email', $user->email)
            ->set('form.password', 'password')
            ->call('login');

        $component->assertRedirect(route('dashboard', absolute: false));
        $this->assertAuthenticated();
    }

    public function test_inactive_employee_can_still_log_in(): void
    {
        $ctx = $this->makeOrganization('InactLogin');
        [$user] = $this->makeEmployeeWithStatus($ctx, 'inactive');

        $component = Volt::test('pages.auth.login')
            ->set('form.email', $user->email)
            ->set('form.password', 'password')
            ->call('login');

        $component->assertRedirect(route('dashboard', absolute: false));
        $this->assertAuthenticated();
    }

    public function test_terminated_employee_sees_red_block_and_nav_badge(): void
    {
        $ctx = $this->makeOrganization('TermDash');
        [$user] = $this->makeEmployeeWithStatus($ctx, 'terminated');

        $this->actingAs($user)
            ->get(route('dashboard.employee'))
            ->assertOk()
            ->assertSee('You have been terminated')
            ->assertSee('Terminated');
    }

    public function test_inactive_employee_sees_amber_block_and_nav_badge(): void
    {
        $ctx = $this->makeOrganization('InactDash');
        [$user] = $this->makeEmployeeWithStatus($ctx, 'inactive');

        $this->actingAs($user)
            ->get(route('dashboard.employee'))
            ->assertOk()
            ->assertSee('Your employment status is inactive')
            ->assertSee('Inactive');
    }

    public function test_active_employee_sees_no_status_blocks_or_badge(): void
    {
        $ctx = $this->makeOrganization('ActiveDash');
        [$user] = $this->makeEmployeeWithStatus($ctx, 'active');

        $this->actingAs($user)
            ->get(route('dashboard.employee'))
            ->assertOk()
            ->assertDontSee('You have been terminated')
            ->assertDontSee('Your employment status is inactive')
            ->assertDontSee('bg-red-100 text-red-700');
    }

    public function test_status_badge_appears_on_other_pages_too(): void
    {
        $ctx = $this->makeOrganization('TermBadge');
        [$user] = $this->makeEmployeeWithStatus($ctx, 'terminated');

        // The nav renders on every page — badge is not dashboard-only.
        $this->actingAs($user)
            ->get(route('notifications.index'))
            ->assertOk()
            ->assertSee('Terminated');
    }

    public function test_terminating_an_employee_sends_them_a_notification(): void
    {
        Notification::fake();

        $ctx = $this->makeOrganization('TermNotify');
        $admin = $this->makeUser($ctx['organization'], 'company_admin', $ctx['roles']);
        [$user, $employee] = $this->makeEmployeeWithStatus($ctx, 'active');

        $this->actingAs($admin)->put(route('employees.update', $employee), [
            'name' => $user->name,
            'email' => $user->email,
            'status' => 'terminated',
            'employment_type' => 'full_time',
        ])->assertRedirect(route('employees.show', $employee));

        Notification::assertSentTo($user, EmploymentStatusChanged::class);
    }

    public function test_marking_an_employee_inactive_sends_them_a_notification(): void
    {
        Notification::fake();

        $ctx = $this->makeOrganization('InactNotify');
        $admin = $this->makeUser($ctx['organization'], 'company_admin', $ctx['roles']);
        [$user, $employee] = $this->makeEmployeeWithStatus($ctx, 'active');

        $this->actingAs($admin)->put(route('employees.update', $employee), [
            'name' => $user->name,
            'email' => $user->email,
            'status' => 'inactive',
            'employment_type' => 'full_time',
        ])->assertRedirect(route('employees.show', $employee));

        Notification::assertSentTo($user, EmploymentStatusChanged::class);
    }

    public function test_reactivating_a_terminated_employee_sends_no_status_notification(): void
    {
        Notification::fake();

        $ctx = $this->makeOrganization('Reactivate');
        $admin = $this->makeUser($ctx['organization'], 'company_admin', $ctx['roles']);
        [$user, $employee] = $this->makeEmployeeWithStatus($ctx, 'terminated');

        $this->actingAs($admin)->put(route('employees.update', $employee), [
            'name' => $user->name,
            'email' => $user->email,
            'status' => 'active',
            'employment_type' => 'full_time',
        ])->assertRedirect(route('employees.show', $employee));

        Notification::assertNotSentTo($user, EmploymentStatusChanged::class);
    }

    public function test_updating_other_fields_sends_no_status_notification(): void
    {
        Notification::fake();

        $ctx = $this->makeOrganization('NoNotify');
        $admin = $this->makeUser($ctx['organization'], 'company_admin', $ctx['roles']);
        [$user, $employee] = $this->makeEmployeeWithStatus($ctx, 'active');

        $this->actingAs($admin)->put(route('employees.update', $employee), [
            'name' => $user->name,
            'email' => $user->email,
            'status' => 'active',
            'employment_type' => 'full_time',
            'phone' => '01700000000',
        ])->assertRedirect(route('employees.show', $employee));

        Notification::assertNotSentTo($user, EmploymentStatusChanged::class);
    }

    public function test_users_without_an_employee_profile_see_no_badge(): void
    {
        $ctx = $this->makeOrganization('NoProfile');
        $admin = $this->makeUser($ctx['organization'], 'company_admin', $ctx['roles']);

        $this->actingAs($admin)
            ->get(route('dashboard'))
            ->assertRedirect(route('dashboard.admin'));

        $this->actingAs($admin)
            ->get(route('dashboard.admin'))
            ->assertOk()
            ->assertDontSee('bg-red-100 text-red-700');
    }
}
