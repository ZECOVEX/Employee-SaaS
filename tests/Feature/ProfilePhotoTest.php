<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesOrganizations;
use Tests\TestCase;

/**
 * Post-Phase 19/20 — profile customization, role-scoped:
 *  - identity editors (company_admin/hr/manager with employees.edit): avatar
 *    upload (public disk) + contact phone on their own user row;
 *  - employees: emergency contact only, written to the employee record so
 *    admins see it on the employee profile (synced).
 */
class ProfilePhotoTest extends TestCase
{
    use CreatesOrganizations;
    use RefreshDatabase;

    public function test_user_can_upload_profile_photo_and_set_phone(): void
    {
        Storage::fake('public');

        $ctx = $this->makeOrganization('ProfCo');
        $user = $this->makeUser($ctx['organization'], 'company_admin', $ctx['roles']);

        $response = $this->actingAs($user)->call('PUT', route('profile.update'), [
            'phone' => '+1 555 0100',
        ], [], [
            'avatar' => $this->png(),
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('status', 'profile-updated');

        $user->refresh();
        $this->assertSame('+1 555 0100', $user->phone);
        $this->assertNotNull($user->avatar_path);
        $this->assertStringStartsWith('avatars/', $user->avatar_path);
        Storage::disk('public')->assertExists($user->avatar_path);

        $this->assertDatabaseHas('audit_logs', [
            'organization_id' => $ctx['organization']->id,
            'action' => 'profile.updated',
        ]);
    }

    public function test_non_image_avatar_is_rejected(): void
    {
        Storage::fake('public');

        $ctx = $this->makeOrganization('ProfPdf');
        $user = $this->makeUser($ctx['organization'], 'company_admin', $ctx['roles']);

        $response = $this->actingAs($user)->call('PUT', route('profile.update'), [], [], [
            'avatar' => UploadedFile::fake()->create('cv.pdf', 100, 'application/pdf'),
        ]);

        $response->assertSessionHasErrors('avatar');
        $this->assertNull($user->refresh()->avatar_path);
    }

    public function test_avatar_remove_deletes_the_stored_file(): void
    {
        Storage::fake('public');

        $ctx = $this->makeOrganization('ProfRm');
        $user = $this->makeUser($ctx['organization'], 'company_admin', $ctx['roles']);

        $this->actingAs($user)->call('PUT', route('profile.update'), [], [], [
            'avatar' => $this->png(),
        ])->assertSessionHasNoErrors();

        $path = $user->refresh()->avatar_path;
        $this->assertNotNull($path);
        Storage::disk('public')->assertExists($path);

        $this->actingAs($user)->call('PUT', route('profile.update'), [
            'avatar_remove' => 1,
        ])->assertSessionHasNoErrors();

        $this->assertNull($user->refresh()->avatar_path);
        Storage::disk('public')->assertMissing($path);
    }

    public function test_phone_only_update_keeps_existing_photo(): void
    {
        Storage::fake('public');

        $ctx = $this->makeOrganization('ProfPh');
        $user = $this->makeUser($ctx['organization'], 'company_admin', $ctx['roles']);

        $this->actingAs($user)->call('PUT', route('profile.update'), [], [], [
            'avatar' => $this->png(),
        ])->assertSessionHasNoErrors();

        $path = $user->refresh()->avatar_path;

        $this->actingAs($user)->call('PUT', route('profile.update'), [
            'phone' => '+49 30 1234567',
        ])->assertSessionHasNoErrors();

        $user->refresh();
        $this->assertSame('+49 30 1234567', $user->phone);
        $this->assertSame($path, $user->avatar_path);
        Storage::disk('public')->assertExists($path);
    }

    public function test_admin_sees_avatar_in_nav_after_upload(): void
    {
        Storage::fake('public');

        $ctx = $this->makeOrganization('ProfNav');
        $user = $this->makeUser($ctx['organization'], 'company_admin', $ctx['roles']);

        $this->actingAs($user)->call('PUT', route('profile.update'), [], [], [
            'avatar' => $this->png(),
        ])->assertSessionHasNoErrors();

        $this->assertNotNull($user->refresh()->avatar_path);

        // The nav avatar renders the stored public-disk URL.
        $this->actingAs($user)
            ->get(route('dashboard.admin'))
            ->assertOk()
            ->assertSee('storage/avatars/');
    }

    public function test_employee_can_update_emergency_contact_and_admin_sees_it(): void
    {
        $ctx = $this->makeOrganization('EmergeCo');
        $admin = $this->makeUser($ctx['organization'], 'company_admin', $ctx['roles']);
        $user = $this->makeUser($ctx['organization'], 'employee', $ctx['roles']);
        $employee = $this->makeEmployee($ctx['organization'], $user, 'EMP-EC');

        $this->actingAs($user)->call('PUT', route('profile.update'), [
            'emergency_contact_name' => 'Jane Doe (sister)',
            'emergency_contact_phone' => '+1 555 0199',
        ])->assertSessionHasNoErrors();

        // Synced to the employee record ("user database" for the org).
        $employee->refresh();
        $this->assertSame('Jane Doe (sister)', $employee->emergency_contact_name);
        $this->assertSame('+1 555 0199', $employee->emergency_contact_phone);

        $this->assertDatabaseHas('audit_logs', [
            'organization_id' => $ctx['organization']->id,
            'action' => 'profile.emergency_updated',
        ]);

        // Admin sees it on the employee profile page.
        $this->actingAs($admin)
            ->get(route('employees.show', $employee))
            ->assertOk()
            ->assertSee('Jane Doe (sister)')
            ->assertSee('+1 555 0199');
    }

    public function test_employee_can_clear_emergency_contact(): void
    {
        $ctx = $this->makeOrganization('EmergeClr');
        $user = $this->makeUser($ctx['organization'], 'employee', $ctx['roles']);
        $employee = $this->makeEmployee($ctx['organization'], $user, 'EMP-CL');
        $employee->update([
            'emergency_contact_name' => 'Old Contact',
            'emergency_contact_phone' => '555-0001',
        ]);

        $this->actingAs($user)->call('PUT', route('profile.update'), [
            'emergency_contact_name' => '',
            'emergency_contact_phone' => '',
        ])->assertSessionHasNoErrors();

        $employee->refresh();
        $this->assertNull($employee->emergency_contact_name);
        $this->assertNull($employee->emergency_contact_phone);
    }

    public function test_employee_cannot_change_avatar_or_phone(): void
    {
        Storage::fake('public');

        $ctx = $this->makeOrganization('EmergeLock');
        $user = $this->makeUser($ctx['organization'], 'employee', $ctx['roles']);
        $this->makeEmployee($ctx['organization'], $user, 'EMP-EL');

        $response = $this->actingAs($user)->call('PUT', route('profile.update'), [
            'phone' => '555-0000',
        ], [], [
            'avatar' => $this->png(),
        ]);

        $response->assertSessionHasErrors(['avatar', 'phone']);

        $user->refresh();
        $this->assertNull($user->avatar_path);
        $this->assertNull($user->phone);
    }

    public function test_employee_without_employee_record_cannot_set_emergency_contact(): void
    {
        $ctx = $this->makeOrganization('EmergeNone');
        $user = $this->makeUser($ctx['organization'], 'employee', $ctx['roles']);

        $this->actingAs($user)->call('PUT', route('profile.update'), [
            'emergency_contact_name' => 'Nobody',
        ])->assertSessionHasErrors('emergency_contact_name');
    }

    public function test_profile_page_scopes_cards_by_role(): void
    {
        $ctx = $this->makeOrganization('EmergeUI');
        $admin = $this->makeUser($ctx['organization'], 'company_admin', $ctx['roles']);
        $user = $this->makeUser($ctx['organization'], 'employee', $ctx['roles']);
        $this->makeEmployee($ctx['organization'], $user, 'EMP-UI');

        // Employee: emergency contact card, no photo/phone editor.
        $this->actingAs($user)
            ->get(route('profile'))
            ->assertOk()
            ->assertSee('Emergency contact')
            ->assertSee('data-test="ec-name"', false)
            ->assertDontSee('data-test="avatar-input"', false)
            ->assertDontSee('data-test="phone-input"', false);

        // Admin: photo & phone card, no emergency editor.
        $this->actingAs($admin)
            ->get(route('profile'))
            ->assertOk()
            ->assertSee('Photo & phone')
            ->assertSee('data-test="avatar-input"', false)
            ->assertDontSee('data-test="ec-name"', false);
    }

    public function test_profile_update_requires_authentication(): void
    {
        $this->call('PUT', route('profile.update'), ['phone' => '555'])
            ->assertRedirect(route('login'));
    }

    private function png(): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'avatar').'.png';
        file_put_contents($path, base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='
        ));

        return new UploadedFile($path, 'avatar.png', 'image/png', null, true);
    }
}
