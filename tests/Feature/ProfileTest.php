<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\Concerns\CreatesOrganizations;
use Tests\TestCase;

class ProfileTest extends TestCase
{
    use CreatesOrganizations, RefreshDatabase;

    public function test_profile_page_is_displayed(): void
    {
        ['organization' => $org, 'roles' => $roles] = $this->makeOrganization();
        $user = $this->makeUser($org, 'company_admin', $roles);

        $response = $this->actingAs($user)->get('/profile');

        $response
            ->assertOk()
            ->assertSeeVolt('profile.update-profile-information-form')
            ->assertSeeVolt('profile.update-password-form')
            ->assertSeeVolt('profile.delete-user-form');
    }

    public function test_profile_information_can_be_updated(): void
    {
        ['organization' => $org, 'roles' => $roles] = $this->makeOrganization();
        $user = $this->makeUser($org, 'company_admin', $roles);

        $this->actingAs($user);

        $component = Volt::test('profile.update-profile-information-form')
            ->set('name', 'Test User')
            ->set('email', 'test@example.com')
            ->call('updateProfileInformation');

        $component
            ->assertHasNoErrors()
            ->assertNoRedirect();

        $user->refresh();

        $this->assertSame('Test User', $user->name);
        $this->assertSame('test@example.com', $user->email);
        $this->assertNull($user->email_verified_at);
    }

    public function test_email_verification_status_is_unchanged_when_the_email_address_is_unchanged(): void
    {
        ['organization' => $org, 'roles' => $roles] = $this->makeOrganization();
        $user = $this->makeUser($org, 'company_admin', $roles);

        $this->actingAs($user);

        $component = Volt::test('profile.update-profile-information-form')
            ->set('name', $user->name)
            ->set('email', $user->email)
            ->call('updateProfileInformation');

        $component
            ->assertHasNoErrors()
            ->assertNoRedirect();

        $this->assertNotNull($user->refresh()->email_verified_at);
    }

    public function test_user_can_delete_their_account(): void
    {
        ['organization' => $org, 'roles' => $roles] = $this->makeOrganization();
        $user = $this->makeUser($org, 'company_admin', $roles);

        $this->actingAs($user);

        $component = Volt::test('profile.delete-user-form')
            ->set('password', 'password')
            ->call('deleteUser');

        $component
            ->assertHasNoErrors()
            ->assertRedirect('/');

        $this->assertGuest();
        $this->assertNull($user->fresh());
    }

    public function test_correct_password_must_be_provided_to_delete_account(): void
    {
        ['organization' => $org, 'roles' => $roles] = $this->makeOrganization();
        $user = $this->makeUser($org, 'company_admin', $roles);

        $this->actingAs($user);

        $component = Volt::test('profile.delete-user-form')
            ->set('password', 'wrong-password')
            ->call('deleteUser');

        $component
            ->assertHasErrors('password')
            ->assertNoRedirect();

        $this->assertNotNull($user->fresh());
    }

    public function test_employee_cannot_change_own_name_or_email(): void
    {
        ['organization' => $org, 'roles' => $roles] = $this->makeOrganization();
        $user = $this->makeUser($org, 'employee', $roles);

        $this->actingAs($user);

        Volt::test('profile.update-profile-information-form')
            ->set('name', 'Hacked Name')
            ->set('email', 'hacked@example.com')
            ->call('updateProfileInformation')
            ->assertHasErrors('name');

        $user->refresh();

        $this->assertSame('Employee User', $user->name);
        $this->assertStringNotContainsString('hacked', $user->email);
    }

    public function test_employee_profile_page_hides_delete_form_and_locks_identity(): void
    {
        ['organization' => $org, 'roles' => $roles] = $this->makeOrganization();
        $user = $this->makeUser($org, 'employee', $roles);

        $this->actingAs($user)
            ->get('/profile')
            ->assertOk()
            ->assertSeeVolt('profile.update-profile-information-form')
            ->assertDontSee('profile.delete-user-form')
            ->assertSee('managed by HR');
    }

    public function test_employee_cannot_delete_own_account(): void
    {
        ['organization' => $org, 'roles' => $roles] = $this->makeOrganization();
        $user = $this->makeUser($org, 'employee', $roles);

        $this->actingAs($user);

        Volt::test('profile.delete-user-form')
            ->set('password', 'password')
            ->call('deleteUser')
            ->assertHasErrors('password');

        $this->assertNotNull($user->fresh());
        $this->assertAuthenticated();
    }
}
