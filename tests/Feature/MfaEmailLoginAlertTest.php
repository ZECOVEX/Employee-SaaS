<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\UserLoginHistory;
use App\Notifications\LoginAlert;
use App\Notifications\MfaEmailCode;
use App\Services\Geo\LoginHistoryService;
use App\Services\Mfa\MfaService;
use App\Services\Mfa\TotpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Volt\Volt;
use Tests\Concerns\CreatesOrganizations;
use Tests\TestCase;

class MfaEmailLoginAlertTest extends TestCase
{
    use CreatesOrganizations, RefreshDatabase;

    public function test_email_method_setup_mails_a_code_and_enables_mfa_without_a_secret(): void
    {
        Notification::fake();

        ['organization' => $org, 'roles' => $roles] = $this->makeOrganization();
        $user = $this->makeUser($org, 'company_admin', $roles);

        $code = null;
        $this->actingAs($user);
        Volt::test('profile.mfa-form')
            ->call('startSetup', MfaService::METHOD_EMAIL)
            ->assertHasNoErrors();

        Notification::assertSentTo($user, MfaEmailCode::class, function (MfaEmailCode $notification) use (&$code) {
            $code = $notification->code;

            return $notification->context === 'setup';
        });

        $this->assertIsString($code);

        Volt::test('profile.mfa-form')
            ->set('code', $code)
            ->call('confirm')
            ->assertHasNoErrors();

        $user->refresh();
        $this->assertTrue($user->mfa_enabled);
        $this->assertSame(MfaService::METHOD_EMAIL, $user->mfa_method);
        $this->assertNull($user->mfa_secret);
        $this->assertCount(MfaService::RECOVERY_CODE_COUNT, $user->mfa_recovery_codes ?? []);
    }

    public function test_email_method_setup_rejects_a_wrong_code(): void
    {
        Notification::fake();

        ['organization' => $org, 'roles' => $roles] = $this->makeOrganization();
        $user = $this->makeUser($org, 'company_admin', $roles);

        $this->actingAs($user);
        Volt::test('profile.mfa-form')
            ->call('startSetup', MfaService::METHOD_EMAIL);

        Volt::test('profile.mfa-form')
            ->set('code', '000000')
            ->call('confirm')
            ->assertHasErrors(['code']);

        $this->assertFalse($user->refresh()->mfa_enabled);
    }

    public function test_email_method_login_challenge_mails_a_code_and_verifies(): void
    {
        Notification::fake();

        ['organization' => $org, 'roles' => $roles] = $this->makeOrganization();
        $user = $this->makeUser($org, 'company_admin', $roles, ['password' => 'password']);

        $mfa = app(MfaService::class);
        $mfa->beginSetup($user, MfaService::METHOD_EMAIL);
        $setupCode = $this->codeFor($user);
        $mfa->confirmSetup($user, $setupCode);

        Notification::fake();

        Volt::test('pages.auth.login')
            ->set('form.email', $user->email)
            ->set('form.password', 'password')
            ->call('login')
            ->assertRedirect(route('mfa.challenge', absolute: false));

        $component = Volt::test('pages.auth.mfa-challenge');
        $challengeCode = $this->codeFor($user);
        $this->assertIsString($challengeCode);

        $component
            ->set('code', $challengeCode)
            ->call('verify')
            ->assertRedirect(route('dashboard', absolute: false));

        $this->assertAuthenticatedAs($user);
    }

    public function test_disable_with_recovery_code_when_the_authenticator_app_was_removed(): void
    {
        ['organization' => $org, 'roles' => $roles] = $this->makeOrganization();
        $user = $this->makeUser($org, 'company_admin', $roles, ['password' => 'password']);

        $mfa = app(MfaService::class);
        $secret = $mfa->beginSetup($user);
        $recoveryCodes = $mfa->confirmSetup($user, TotpService::codeAt($secret));

        $this->actingAs($user);
        Volt::test('profile.mfa-form')
            ->set('password', 'password')
            ->set('code', $recoveryCodes[0])
            ->call('disable')
            ->assertHasNoErrors();

        $user->refresh();
        $this->assertFalse($user->mfa_enabled);
        $this->assertNull($user->mfa_method);
        $this->assertNull($user->mfa_secret);
    }

    public function test_login_alert_toggle_persists_and_writes_an_audit_row(): void
    {
        ['organization' => $org, 'roles' => $roles] = $this->makeOrganization();
        $user = $this->makeUser($org, 'employee', $roles);

        $this->actingAs($user)
            ->post(route('profile.login-alerts'), ['enabled' => '1'])
            ->assertRedirect();

        $this->assertTrue($user->refresh()->login_alerts_enabled);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'profile.login_alerts_updated',
            'resource_id' => $user->id,
        ]);
    }

    public function test_login_alert_email_carries_ip_location_and_device(): void
    {
        Notification::fake();

        ['organization' => $org, 'roles' => $roles] = $this->makeOrganization();
        $user = $this->makeUser($org, 'employee', $roles);
        $user->forceFill(['login_alerts_enabled' => true])->save();

        app(LoginHistoryService::class)->record(
            $user,
            UserLoginHistory::STATUS_SUCCESS,
            '203.0.113.7',
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
        );

        Notification::assertSentTo($user, LoginAlert::class, function (LoginAlert $notification) {
            $lines = implode("\n", $notification->mailLines());

            return str_contains($lines, '203.0.113.7')
                && str_contains($lines, 'Chrome on Windows')
                && str_contains($lines, 'Location:');
        });
    }

    public function test_no_login_alert_when_the_opt_in_is_off(): void
    {
        Notification::fake();

        ['organization' => $org, 'roles' => $roles] = $this->makeOrganization();
        $user = $this->makeUser($org, 'employee', $roles);

        app(LoginHistoryService::class)->record(
            $user,
            UserLoginHistory::STATUS_SUCCESS,
            '203.0.113.7',
            'Mozilla/5.0',
        );

        Notification::assertNotSentTo($user, LoginAlert::class);
    }

    public function test_login_alert_toggle_requires_authentication(): void
    {
        $this->post(route('profile.login-alerts'), ['enabled' => '1'])
            ->assertRedirect(route('login'));
    }

    /** Pull the most recent MfaEmailCode sent to the user (Notification::fake). */
    private function codeFor(User $user): ?string
    {
        $code = null;

        Notification::assertSentTo($user, MfaEmailCode::class, function (MfaEmailCode $notification) use (&$code) {
            $code = $notification->code;

            return true;
        });

        return $code;
    }
}
