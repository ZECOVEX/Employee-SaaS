<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\CreatesOrganizations;
use Tests\TestCase;

class SsoTest extends TestCase
{
    use CreatesOrganizations, RefreshDatabase;

    protected function enableSso($organization, array $overrides = []): void
    {
        $settings = array_merge([
            'sso_enabled' => true,
            'sso_name' => 'Acme SSO',
            'sso_discovery_url' => 'https://idp.example.test/realms/acme',
            'sso_client_id' => 'acme-client',
            'sso_client_secret' => encrypt('acme-secret'),
            'sso_allowed_domains' => ['example.test'],
        ], $overrides);

        $organization->update(['settings' => $settings]);
    }

    protected function fakeDiscovery(): void
    {
        Http::fake([
            'idp.example.test/*' => Http::response([
                'issuer' => 'https://idp.example.test/realms/acme',
                'authorization_endpoint' => 'https://idp.example.test/realms/acme/protocol/openid-connect/auth',
                'token_endpoint' => 'https://idp.example.test/realms/acme/protocol/openid-connect/token',
                'userinfo_endpoint' => 'https://idp.example.test/realms/acme/protocol/openid-connect/userinfo',
            ], 200),
        ]);
    }

    public function test_sso_settings_require_settings_manage(): void
    {
        ['organization' => $org, 'roles' => $roles] = $this->makeOrganization();
        $hr = $this->makeUser($org, 'hr', $roles);
        $admin = $this->makeUser($org, 'company_admin', $roles);

        $this->actingAs($hr)->get(route('sso.edit'))->assertForbidden();
        $this->actingAs($admin)->get(route('sso.edit'))->assertOk();
    }

    public function test_saving_sso_settings_encrypts_the_secret_and_audits(): void
    {
        ['organization' => $org, 'roles' => $roles] = $this->makeOrganization();
        $admin = $this->makeUser($org, 'company_admin', $roles);

        $this->actingAs($admin)->put(route('sso.update'), [
            'sso_enabled' => '1',
            'sso_name' => 'Acme SSO',
            'sso_discovery_url' => 'https://idp.example.test/realms/acme',
            'sso_client_id' => 'acme-client',
            'sso_client_secret' => 'plain-secret',
            'sso_allowed_domains' => 'example.test, example.org',
        ])->assertRedirect(route('sso.edit'));

        $org->refresh();
        $this->assertTrue($org->settings['sso_enabled']);
        $this->assertSame('example.test', $org->settings['sso_allowed_domains'][0]);
        // Stored encrypted — plaintext must not appear in the settings JSON.
        $this->assertStringNotContainsString('plain-secret', json_encode($org->settings));
        $this->assertSame('plain-secret', decrypt($org->settings['sso_client_secret']));

        $this->assertDatabaseHas('audit_logs', ['action' => 'sso.settings_updated']);
    }

    public function test_enabled_orgs_appear_on_the_login_page(): void
    {
        ['organization' => $org] = $this->makeOrganization();
        $this->enableSso($org);

        $this->get(route('login'))
            ->assertOk()
            ->assertSee('Acme SSO');
    }

    public function test_redirect_to_provider_carries_state(): void
    {
        ['organization' => $org] = $this->makeOrganization();
        $this->enableSso($org);
        $this->fakeDiscovery();

        $response = $this->get(route('sso.login', $org));

        $response->assertRedirect();
        $this->assertStringContainsString('protocol/openid-connect/auth', $response->headers->get('Location'));
        $this->assertNotNull(session('sso.state'));
    }

    public function test_redirect_404s_when_sso_is_disabled(): void
    {
        ['organization' => $org] = $this->makeOrganization();

        $this->get(route('sso.login', $org))->assertNotFound();
    }

    public function test_callback_provisions_a_new_employee_and_logs_in(): void
    {
        ['organization' => $org] = $this->makeOrganization();
        $this->enableSso($org);

        Http::fake([
            'idp.example.test/realms/acme/.well-known/openid-configuration' => Http::response([
                'issuer' => 'https://idp.example.test/realms/acme',
                'authorization_endpoint' => 'https://idp.example.test/realms/acme/protocol/openid-connect/auth',
                'token_endpoint' => 'https://idp.example.test/realms/acme/protocol/openid-connect/token',
                'userinfo_endpoint' => 'https://idp.example.test/realms/acme/protocol/openid-connect/userinfo',
            ], 200),
            'idp.example.test/*realms/acme/protocol/openid-connect/token' => Http::response([
                'access_token' => 'at-123',
                'token_type' => 'Bearer',
            ], 200),
            'idp.example.test/*userinfo' => Http::response([
                'sub' => 'idp-user-1',
                'email' => 'newuser@example.test',
                'name' => 'New SSO User',
            ], 200),
        ]);

        $state = 'state-abc';
        $this->withSession(['sso.state' => $state, 'sso.organization_id' => $org->id])
            ->get(route('sso.callback', ['state' => $state, 'code' => 'auth-code']))
            ->assertRedirect(route('dashboard'));

        $this->assertAuthenticated();

        $user = User::where('email', 'newuser@example.test')->first();
        $this->assertNotNull($user);
        $this->assertSame($org->id, $user->organization_id);
        $this->assertDatabaseHas('audit_logs', ['action' => 'sso.user_provisioned']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'sso.login']);
    }

    public function test_callback_rejects_state_mismatch(): void
    {
        $this->withSession(['sso.state' => 'expected'])
            ->get(route('sso.callback', ['state' => 'tampered', 'code' => 'x']))
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors('email');
    }

    public function test_callback_rejects_disallowed_domains(): void
    {
        ['organization' => $org] = $this->makeOrganization();
        $this->enableSso($org);

        Http::fake([
            'idp.example.test/realms/acme/.well-known/openid-configuration' => Http::response([
                'issuer' => 'https://idp.example.test/realms/acme',
                'authorization_endpoint' => 'https://idp.example.test/realms/acme/protocol/openid-connect/auth',
                'token_endpoint' => 'https://idp.example.test/realms/acme/protocol/openid-connect/token',
                'userinfo_endpoint' => 'https://idp.example.test/realms/acme/protocol/openid-connect/userinfo',
            ], 200),
            'idp.example.test/*token' => Http::response(['access_token' => 'at'], 200),
            'idp.example.test/*userinfo' => Http::response([
                'email' => 'someone@outsider.test',
                'name' => 'Outsider',
            ], 200),
        ]);

        $this->withSession(['sso.state' => 's1', 'sso.organization_id' => $org->id])
            ->get(route('sso.callback', ['state' => 's1', 'code' => 'c']))
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_callback_links_an_existing_user_in_the_same_org(): void
    {
        ['organization' => $org, 'roles' => $roles] = $this->makeOrganization();
        $existing = $this->makeUser($org, 'employee', $roles, ['email' => 'member@example.test']);
        $this->enableSso($org);

        Http::fake([
            'idp.example.test/realms/acme/.well-known/openid-configuration' => Http::response([
                'issuer' => 'https://idp.example.test/realms/acme',
                'authorization_endpoint' => 'https://idp.example.test/realms/acme/protocol/openid-connect/auth',
                'token_endpoint' => 'https://idp.example.test/realms/acme/protocol/openid-connect/token',
                'userinfo_endpoint' => 'https://idp.example.test/realms/acme/protocol/openid-connect/userinfo',
            ], 200),
            'idp.example.test/*token' => Http::response(['access_token' => 'at'], 200),
            'idp.example.test/*userinfo' => Http::response([
                'email' => 'member@example.test',
                'name' => 'Member',
            ], 200),
        ]);

        $this->withSession(['sso.state' => 's2', 'sso.organization_id' => $org->id])
            ->get(route('sso.callback', ['state' => 's2', 'code' => 'c']))
            ->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($existing);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'sso.user_provisioned']);
    }

    public function test_login_page_shows_no_sso_section_when_no_orgs_enable_it(): void
    {
        $this->makeOrganization();

        $this->get(route('login'))
            ->assertOk()
            ->assertDontSee('or sign in with SSO');
    }
}
