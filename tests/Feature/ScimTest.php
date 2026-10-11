<?php

namespace Tests\Feature;

use App\Models\ScimToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesOrganizations;
use Tests\TestCase;

class ScimTest extends TestCase
{
    use CreatesOrganizations, RefreshDatabase;

    protected function issueToken($org, $admin, string $name = 'Okta'): string
    {
        return ScimToken::issue($org, $name, $admin);
    }

    public function test_scim_endpoints_require_a_bearer_token(): void
    {
        $this->getJson('/api/scim/v2/Users')->assertUnauthorized();
        $this->getJson('/api/scim/v2/Users', ['Authorization' => 'Bearer scim_bogus'])->assertUnauthorized();
    }

    public function test_service_provider_config_is_public_within_auth(): void
    {
        ['organization' => $org, 'roles' => $roles] = $this->makeOrganization();
        $admin = $this->makeUser($org, 'company_admin', $roles);
        $token = $this->issueToken($org, $admin);

        $this->getJson('/api/scim/v2/ServiceProviderConfig', ['Authorization' => 'Bearer '.$token])
            ->assertOk()
            ->assertJsonPath('schemas.0', 'urn:ietf:params:scim:schemas:core:2.0:ServiceProviderConfig');
    }

    public function test_create_list_show_update_and_delete_user(): void
    {
        ['organization' => $org, 'roles' => $roles] = $this->makeOrganization();
        $admin = $this->makeUser($org, 'company_admin', $roles);
        $token = $this->issueToken($org, $admin);
        $headers = ['Authorization' => 'Bearer '.$token];

        $created = $this->postJson('/api/scim/v2/Users', [
            'userName' => 'provisioned@example.test',
            'name' => ['formatted' => 'Provisioned User'],
        ], $headers);

        $created->assertCreated()->assertJsonPath('userName', 'provisioned@example.test');
        $id = $created->json('id');

        $this->assertDatabaseHas('users', [
            'organization_id' => $org->id,
            'email' => 'provisioned@example.test',
            'must_change_password' => true,
        ]);

        $this->getJson('/api/scim/v2/Users/'.$id, $headers)
            ->assertOk()
            ->assertJsonPath('id', $id);

        $this->getJson('/api/scim/v2/Users', $headers)
            ->assertOk()
            ->assertJsonPath('totalResults', 2) // admin + provisioned
            ->assertJsonPath('Resources.1.userName', 'provisioned@example.test');

        $this->putJson('/api/scim/v2/Users/'.$id, [
            'name' => ['formatted' => 'Renamed User'],
        ], $headers)
            ->assertOk()
            ->assertJsonPath('name.formatted', 'Renamed User');

        $this->deleteJson('/api/scim/v2/Users/'.$id, [], $headers)->assertNoContent();
        $this->assertDatabaseMissing('users', ['id' => (int) $id]);
    }

    public function test_scim_user_is_scoped_to_the_token_organization(): void
    {
        ['organization' => $org, 'roles' => $roles] = $this->makeOrganization();
        ['organization' => $other, 'roles' => $otherRoles] = $this->makeOrganization('Other Co');

        $admin = $this->makeUser($org, 'company_admin', $roles);
        $otherUser = $this->makeUser($other, 'company_admin', $otherRoles);

        $token = $this->issueToken($org, $admin);
        $headers = ['Authorization' => 'Bearer '.$token];

        $this->getJson('/api/scim/v2/Users/'.$otherUser->id, $headers)->assertNotFound();
        $this->deleteJson('/api/scim/v2/Users/'.$otherUser->id, [], $headers)->assertNotFound();
    }

    public function test_duplicate_user_name_returns_a_scim_error(): void
    {
        ['organization' => $org, 'roles' => $roles] = $this->makeOrganization();
        $admin = $this->makeUser($org, 'company_admin', $roles, ['email' => 'taken@example.test']);
        $token = $this->issueToken($org, $admin);

        $this->postJson('/api/scim/v2/Users', ['userName' => 'taken@example.test'], ['Authorization' => 'Bearer '.$token])
            ->assertStatus(422);
    }

    public function test_groups_map_to_departments(): void
    {
        ['organization' => $org, 'roles' => $roles] = $this->makeOrganization();
        $admin = $this->makeUser($org, 'company_admin', $roles);
        $token = $this->issueToken($org, $admin);
        $headers = ['Authorization' => 'Bearer '.$token];

        $created = $this->postJson('/api/scim/v2/Groups', ['displayName' => 'Platform'], $headers);
        $created->assertCreated()->assertJsonPath('displayName', 'Platform');
        $id = $created->json('id');

        $this->assertDatabaseHas('departments', [
            'organization_id' => $org->id,
            'name' => 'Platform',
        ]);

        $this->getJson('/api/scim/v2/Groups', $headers)
            ->assertOk()
            ->assertJsonPath('totalResults', 1);

        $this->putJson('/api/scim/v2/Groups/'.$id, ['displayName' => 'Platform Eng'], $headers)
            ->assertOk()
            ->assertJsonPath('displayName', 'Platform Eng');

        $this->deleteJson('/api/scim/v2/Groups/'.$id, [], $headers)->assertNoContent();
    }

    public function test_revoked_token_is_rejected(): void
    {
        ['organization' => $org, 'roles' => $roles] = $this->makeOrganization();
        $admin = $this->makeUser($org, 'company_admin', $roles);
        $token = $this->issueToken($org, $admin);

        ScimToken::where('name', 'Okta')->firstOrFail()
            ->forceFill(['revoked_at' => now()])->save();

        $this->getJson('/api/scim/v2/Users', ['Authorization' => 'Bearer '.$token])
            ->assertUnauthorized();
    }

    public function test_token_creation_is_audited_and_shown_once(): void
    {
        ['organization' => $org, 'roles' => $roles] = $this->makeOrganization();
        $admin = $this->makeUser($org, 'company_admin', $roles);

        $this->actingAs($admin)
            ->post(route('scim-tokens.store'), ['token_name' => 'Entra ID'])
            ->assertRedirect(route('api-keys.index'))
            ->assertSessionHas('new_scim_token');

        $this->assertStringStartsWith('scim_', session('new_scim_token'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'scim.token_created']);
    }
}
