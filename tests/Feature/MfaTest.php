<?php

namespace Tests\Feature;

use App\Services\Mfa\MfaService;
use App\Services\Mfa\TotpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\Concerns\CreatesOrganizations;
use Tests\TestCase;

class MfaTest extends TestCase
{
    use CreatesOrganizations, RefreshDatabase;

    public function test_totp_round_trip_verifies_the_current_code(): void
    {
        $secret = TotpService::generateSecret();

        $this->assertTrue(TotpService::verify($secret, TotpService::codeAt($secret)));
        $this->assertFalse(TotpService::verify($secret, '000000'));
        $this->assertFalse(TotpService::verify($secret, 'abc'));
    }

    public function test_otpauth_url_contains_the_secret_and_issuer(): void
    {
        $url = TotpService::otpauthUrl('user@example.test', 'JBSWY3DPEHPK3PXP', 'TestApp');

        $this->assertStringContainsString('secret=JBSWY3DPEHPK3PXP', $url);
        $this->assertStringContainsString('issuer=TestApp', $url);
        $this->assertStringContainsString('period=30', $url);
    }

    public function test_confirm_setup_persists_mfa_and_returns_recovery_codes(): void
    {
        ['organization' => $org, 'roles' => $roles] = $this->makeOrganization();
        $user = $this->makeUser($org, 'company_admin', $roles);

        $mfa = app(MfaService::class);
        $secret = $mfa->beginSetup($user);

        $this->assertSame($secret, session(MfaService::PENDING_SESSION_KEY));

        $codes = $mfa->confirmSetup($user, TotpService::codeAt($secret));

        $this->assertCount(MfaService::RECOVERY_CODE_COUNT, $codes);

        $user->refresh();
        $this->assertTrue($user->mfa_enabled);
        $this->assertCount(MfaService::RECOVERY_CODE_COUNT, $user->mfa_recovery_codes ?? []);
    }

    public function test_confirm_setup_rejects_a_wrong_code(): void
    {
        ['organization' => $org, 'roles' => $roles] = $this->makeOrganization();
        $user = $this->makeUser($org, 'company_admin', $roles);

        $mfa = app(MfaService::class);
        $mfa->beginSetup($user);

        $this->assertSame([], $mfa->confirmSetup($user, '000000'));
        $this->assertFalse($user->refresh()->mfa_enabled);
    }

    public function test_recovery_code_is_single_use(): void
    {
        ['organization' => $org, 'roles' => $roles] = $this->makeOrganization();
        $user = $this->makeUser($org, 'company_admin', $roles);

        $mfa = app(MfaService::class);
        $secret = $mfa->beginSetup($user);
        $codes = $mfa->confirmSetup($user, TotpService::codeAt($secret));

        $this->assertTrue($mfa->consumeRecoveryCode($user, $codes[0]));
        $this->assertFalse($mfa->consumeRecoveryCode($user->refresh(), $codes[0]));
    }

    public function test_login_with_mfa_redirects_to_the_challenge_page(): void
    {
        ['organization' => $org, 'roles' => $roles] = $this->makeOrganization();
        $user = $this->makeUser($org, 'company_admin', $roles, ['password' => 'password']);

        $mfa = app(MfaService::class);
        $secret = $mfa->beginSetup($user);
        $mfa->confirmSetup($user, TotpService::codeAt($secret));

        $component = Volt::test('pages.auth.login')
            ->set('form.email', $user->email)
            ->set('form.password', 'password')
            ->call('login');

        $component->assertRedirect(route('mfa.challenge', absolute: false));
        $this->assertGuest();
        $this->assertSame($user->id, session(MfaService::CHALLENGE_SESSION_KEY));
    }

    public function test_challenge_completes_login_with_a_valid_totp_code(): void
    {
        ['organization' => $org, 'roles' => $roles] = $this->makeOrganization();
        $user = $this->makeUser($org, 'company_admin', $roles, ['password' => 'password']);

        $mfa = app(MfaService::class);
        $secret = $mfa->beginSetup($user);
        $mfa->confirmSetup($user, TotpService::codeAt($secret));

        Volt::test('pages.auth.login')
            ->set('form.email', $user->email)
            ->set('form.password', 'password')
            ->call('login');

        $component = Volt::test('pages.auth.mfa-challenge')
            ->set('code', TotpService::codeAt($user->refresh()->mfa_secret))
            ->call('verify');

        $component->assertRedirect(route('dashboard', absolute: false));
        $this->assertAuthenticatedAs($user);
    }

    public function test_challenge_rejects_a_wrong_code(): void
    {
        ['organization' => $org, 'roles' => $roles] = $this->makeOrganization();
        $user = $this->makeUser($org, 'company_admin', $roles, ['password' => 'password']);

        $mfa = app(MfaService::class);
        $secret = $mfa->beginSetup($user);
        $mfa->confirmSetup($user, TotpService::codeAt($secret));

        Volt::test('pages.auth.login')
            ->set('form.email', $user->email)
            ->set('form.password', 'password')
            ->call('login');

        Volt::test('pages.auth.mfa-challenge')
            ->set('code', '000000')
            ->call('verify')
            ->assertHasErrors('code');

        $this->assertGuest();
    }

    public function test_challenge_page_redirects_guests_without_a_pending_user(): void
    {
        $this->get(route('mfa.challenge'))->assertRedirect(route('login'));
    }

    public function test_disable_mfa_requires_password_and_code(): void
    {
        ['organization' => $org, 'roles' => $roles] = $this->makeOrganization();
        $user = $this->makeUser($org, 'company_admin', $roles, ['password' => 'password']);

        $mfa = app(MfaService::class);
        $secret = $mfa->beginSetup($user);
        $mfa->confirmSetup($user, TotpService::codeAt($secret));

        $this->actingAs($user->refresh());

        $mfa->disable($user);

        $this->assertFalse($user->refresh()->mfa_enabled);
        $this->assertNull($user->mfa_secret);
        $this->assertNull($user->mfa_recovery_codes);
    }

    public function test_profile_page_renders_the_mfa_section(): void
    {
        ['organization' => $org, 'roles' => $roles] = $this->makeOrganization();
        $admin = $this->makeUser($org, 'company_admin', $roles);

        $this->actingAs($admin)
            ->get(route('profile'))
            ->assertOk()
            ->assertSee('Two-factor authentication');
    }

    public function test_mfa_setup_renders_the_qr_code(): void
    {
        ['organization' => $org, 'roles' => $roles] = $this->makeOrganization();
        $user = $this->makeUser($org, 'company_admin', $roles);

        $this->actingAs($user);

        $component = Volt::test('profile.mfa-form')
            ->call('startSetup')
            ->assertSee('Authentication code')
            ->assertSee('<svg', false);

        $this->assertNotEmpty($component->get('secret'));
    }
}
