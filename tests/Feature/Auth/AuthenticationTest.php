<?php

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\Concerns\CreatesOrganizations;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use CreatesOrganizations, RefreshDatabase;

    public function test_login_screen_can_be_rendered(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertSee('Login');
    }

    public function test_users_can_authenticate_using_the_login_screen(): void
    {
        ['organization' => $org, 'roles' => $roles] = $this->makeOrganization();
        $user = $this->makeUser($org, 'company_admin', $roles, ['email' => 'auth@example.test']);

        $component = Volt::test('pages.auth.login')
            ->set('form.email', $user->email)
            ->set('form.password', 'password')
            ->call('login');

        $component->assertRedirect(route('dashboard', absolute: false));
        $this->assertAuthenticated();
    }

    public function test_users_can_not_authenticate_with_invalid_password(): void
    {
        ['organization' => $org, 'roles' => $roles] = $this->makeOrganization();
        $user = $this->makeUser($org, 'employee', $roles);

        $component = Volt::test('pages.auth.login')
            ->set('form.email', $user->email)
            ->set('form.password', 'wrong-password')
            ->call('login');

        $component->assertHasErrors('form.email');
        $this->assertGuest();
    }

    public function test_suspended_organization_user_is_logged_out(): void
    {
        ['organization' => $org, 'roles' => $roles] = $this->makeOrganization();
        $user = $this->makeUser($org, 'company_admin', $roles);

        $this->actingAs($user);
        $org->update(['status' => 'suspended']);

        $this->get(route('dashboard.admin'))->assertForbidden();
        $this->assertGuest();
    }
}
