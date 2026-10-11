<?php

namespace Tests\Feature;

use App\Models\ApiKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesOrganizations;
use Tests\TestCase;

class ApiKeyTest extends TestCase
{
    use CreatesOrganizations, RefreshDatabase;

    public function test_api_keys_page_requires_settings_manage(): void
    {
        ['organization' => $org, 'roles' => $roles] = $this->makeOrganization();
        $hr = $this->makeUser($org, 'hr', $roles);
        $admin = $this->makeUser($org, 'company_admin', $roles);

        $this->actingAs($hr)->get(route('api-keys.index'))->assertForbidden();
        $this->actingAs($admin)->get(route('api-keys.index'))->assertOk();
    }

    public function test_creating_a_key_shows_the_secret_once_and_audits(): void
    {
        ['organization' => $org, 'roles' => $roles] = $this->makeOrganization();
        $admin = $this->makeUser($org, 'company_admin', $roles);

        $this->actingAs($admin)
            ->post(route('api-keys.store'), ['name' => 'CI key'])
            ->assertRedirect(route('api-keys.index'))
            ->assertSessionHas('new_api_key');

        $secret = session('new_api_key');
        $this->assertStringStartsWith('emsak_', $secret);
        $this->assertDatabaseHas('api_keys', [
            'organization_id' => $org->id,
            'name' => 'CI key',
            'revoked_at' => null,
        ]);

        $this->assertDatabaseHas('audit_logs', ['action' => 'apikey.created']);

        // Shown exactly once on the post-creation flash…
        $this->actingAs($admin)
            ->get(route('api-keys.index'))
            ->assertOk()
            ->assertSee($secret)
            ->assertSee('CI key');

        // …and never again on a fresh load.
        $this->actingAs($admin)
            ->get(route('api-keys.index'))
            ->assertOk()
            ->assertDontSee($secret);
    }

    public function test_api_key_authenticates_and_scopes_to_its_organization(): void
    {
        ['organization' => $org, 'roles' => $roles] = $this->makeOrganization();
        ['organization' => $other] = $this->makeOrganization('Other Co');

        $admin = $this->makeUser($org, 'company_admin', $roles);
        $otherUser = $this->makeUser($other, 'company_admin');

        $adminEmployee = $this->makeEmployee($org, $admin, 'EMP-A');
        $this->makeEmployee($other, $otherUser, 'EMP-B');

        $secret = ApiKey::issue($org, 'read key', null, $admin);

        $response = $this->getJson('/api/v1/employees', ['X-API-Key' => $secret]);
        $response->assertOk();

        $codes = collect($response->json('data'))->pluck('employee_code');
        $this->assertTrue($codes->contains('EMP-A'));
        $this->assertFalse($codes->contains('EMP-B'));
    }

    public function test_scoped_ability_denies_other_permissions(): void
    {
        ['organization' => $org, 'roles' => $roles] = $this->makeOrganization();
        $admin = $this->makeUser($org, 'company_admin', $roles);
        $this->makeEmployee($org, $admin, 'EMP-A');

        $secret = ApiKey::issue($org, 'attendance only', ['attendance.view'], $admin);

        $this->getJson('/api/v1/attendance', ['X-API-Key' => $secret])->assertOk();
        $this->getJson('/api/v1/employees', ['X-API-Key' => $secret])->assertForbidden();
    }

    public function test_revoked_or_invalid_keys_are_rejected(): void
    {
        ['organization' => $org, 'roles' => $roles] = $this->makeOrganization();
        $admin = $this->makeUser($org, 'company_admin', $roles);
        $this->makeEmployee($org, $admin);

        $secret = ApiKey::issue($org, 'temp', null, $admin);
        $key = ApiKey::where('name', 'temp')->firstOrFail();
        $key->forceFill(['revoked_at' => now()])->save();

        $this->getJson('/api/v1/employees', ['X-API-Key' => $secret])->assertUnauthorized();

        // Tampered key with a valid prefix but wrong body.
        $tampered = substr($secret, 0, 12).str_repeat('x', 40);
        $this->getJson('/api/v1/employees', ['X-API-Key' => $tampered])->assertUnauthorized();

        // Nonsense header.
        $this->getJson('/api/v1/employees', ['X-API-Key' => 'nope'])->assertUnauthorized();
    }

    public function test_admin_can_revoke_a_key(): void
    {
        ['organization' => $org, 'roles' => $roles] = $this->makeOrganization();
        $admin = $this->makeUser($org, 'company_admin', $roles);

        ApiKey::issue($org, 'revoke me', null, $admin);
        $key = ApiKey::where('name', 'revoke me')->firstOrFail();

        $this->actingAs($admin)
            ->delete(route('api-keys.destroy', $key))
            ->assertRedirect(route('api-keys.index'));

        $this->assertNotNull($key->refresh()->revoked_at);
        $this->assertDatabaseHas('audit_logs', ['action' => 'apikey.revoked']);
    }

    public function test_cross_org_key_deletion_is_404(): void
    {
        ['organization' => $org, 'roles' => $roles] = $this->makeOrganization();
        ['organization' => $other, 'roles' => $otherRoles] = $this->makeOrganization('Other Co');

        $admin = $this->makeUser($org, 'company_admin', $roles);
        $otherAdmin = $this->makeUser($other, 'company_admin', $otherRoles);

        ApiKey::issue($org, 'victim', null, $admin);
        $key = ApiKey::where('name', 'victim')->firstOrFail();

        $this->actingAs($otherAdmin)
            ->delete(route('api-keys.destroy', $key))
            ->assertNotFound();
    }
}
