<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
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

    public function test_self_service_deletion_is_prohibited_for_all_roles(): void
    {
        ['organization' => $org, 'roles' => $roles] = $this->makeOrganization();
        $user = $this->makeUser($org, 'company_admin', $roles);

        $this->actingAs($user);

        $component = Volt::test('profile.delete-user-form')
            ->call('deleteUser');

        $component
            ->assertHasErrors('password')
            ->assertNoRedirect();

        $this->assertNotNull($user->fresh());
        $this->assertAuthenticated();
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

    public function test_employee_profile_page_shows_delete_note_and_locks_identity(): void
    {
        ['organization' => $org, 'roles' => $roles] = $this->makeOrganization();
        $user = $this->makeUser($org, 'employee', $roles);

        $this->actingAs($user)
            ->get('/profile')
            ->assertOk()
            ->assertSeeVolt('profile.update-profile-information-form')
            // §43 — the section is always visible: it carries the
            // admin-controlled-removal note + the self data-export link.
            ->assertSeeVolt('profile.delete-user-form')
            ->assertSee('administrator-controlled')
            ->assertSee('managed by HR');
    }

    public function test_any_user_cannot_delete_own_account(): void
    {
        ['organization' => $org, 'roles' => $roles] = $this->makeOrganization();
        $user = $this->makeUser($org, 'employee', $roles);

        $this->actingAs($user);

        Volt::test('profile.delete-user-form')
            ->call('deleteUser')
            ->assertHasErrors('password');

        $this->assertNotNull($user->fresh());
        $this->assertAuthenticated();
    }

    public function test_user_can_download_a_json_export_of_their_own_data(): void
    {
        ['organization' => $org, 'roles' => $roles] = $this->makeOrganization();
        $user = $this->makeUser($org, 'employee', $roles);

        $response = $this->actingAs($user)->get('/profile/export');

        $response
            ->assertOk()
            ->assertHeader('Content-Disposition', 'attachment; filename="my-data-export.json"')
            ->assertJsonPath('user.id', $user->id)
            ->assertJsonPath('user.email', $user->email);

        $this->assertStringNotContainsString($user->password, $response->getContent());
    }

    public function test_deactivated_user_cannot_log_in(): void
    {
        ['organization' => $org, 'roles' => $roles] = $this->makeOrganization();
        $user = $this->makeUser($org, 'employee', $roles);
        $user->forceFill(['deactivated_at' => now()])->save();

        Volt::test('pages.auth.login')
            ->set('form.email', $user->email)
            ->set('form.password', 'password')
            ->call('login')
            ->assertHasErrors('form.email');

        $this->assertGuest();
        $this->assertDatabaseHas('user_login_history', [
            'organization_id' => $org->id,
            'user_id' => $user->id,
            'login_status' => 'FAILED',
        ]);
    }

    public function test_admin_can_deactivate_and_reactivate_a_user(): void
    {
        ['organization' => $org, 'roles' => $roles] = $this->makeOrganization();
        $admin = $this->makeUser($org, 'company_admin', $roles);
        $member = $this->makeUser($org, 'employee', $roles);

        $this->actingAs($admin)
            ->post(route('users.deactivate', $member))
            ->assertRedirect();

        $member->refresh();
        $this->assertNotNull($member->deactivated_at);

        $this->actingAs($admin)
            ->post(route('users.activate', $member))
            ->assertRedirect();

        $this->assertNull($member->refresh()->deactivated_at);
    }

    public function test_admin_cannot_deactivate_the_last_active_company_admin(): void
    {
        ['organization' => $org, 'roles' => $roles] = $this->makeOrganization();
        $admin = $this->makeUser($org, 'company_admin', $roles);

        $this->actingAs($admin)
            ->post(route('users.deactivate', $admin))
            ->assertSessionHasErrors();

        $this->assertNull($admin->refresh()->deactivated_at);
    }

    public function test_password_change_is_audited_without_leaking_the_value(): void
    {
        ['organization' => $org, 'roles' => $roles] = $this->makeOrganization();
        $user = $this->makeUser($org, 'company_admin', $roles);

        $this->actingAs($user);

        Volt::test('profile.update-password-form')
            ->set('current_password', 'password')
            ->set('password', 'a-brand-new-secret')
            ->set('password_confirmation', 'a-brand-new-secret')
            ->call('updatePassword')
            ->assertHasNoErrors();

        $log = AuditLog::withoutGlobalScopes()
            ->where('action', 'password.changed')
            ->firstOrFail();

        $this->assertSame($org->id, $log->organization_id);
        $this->assertSame($user->id, $log->actor_user_id);
        $this->assertSame(['password' => '(redacted)'], $log->new_value);
        $this->assertStringNotContainsString('a-brand-new-secret', $log->toJson());

        $this->assertTrue(Hash::check('a-brand-new-secret', $user->refresh()->password));
    }
}
