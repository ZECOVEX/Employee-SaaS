<?php

namespace Tests\Feature;

use App\Models\UserLoginHistory;
use App\Notifications\HighRiskAttendance;
use App\Notifications\LeaveNoticeAdded;
use App\Notifications\LeaveStatusChanged;
use App\Notifications\LoginAlert;
use App\Notifications\MailTest;
use App\Notifications\OvertimeDetected;
use App\Notifications\PasswordChanged;
use App\Services\MailSettings;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\CreatesOrganizations;
use Tests\TestCase;

class MailSettingsTest extends TestCase
{
    use CreatesOrganizations, RefreshDatabase;

    public function test_email_settings_page_requires_settings_manage(): void
    {
        ['organization' => $org, 'roles' => $roles] = $this->makeOrganization();
        $hr = $this->makeUser($org, 'hr', $roles);
        $admin = $this->makeUser($org, 'company_admin', $roles);

        $this->actingAs($hr)->get(route('mail-settings.edit'))->assertForbidden();
        $this->actingAs($admin)->get(route('mail-settings.edit'))
            ->assertOk()
            ->assertSee('SMTP host')
            ->assertSee('Encryption');
    }

    public function test_admin_saves_smtp_settings_with_encrypted_password_and_audit(): void
    {
        ['organization' => $org, 'roles' => $roles] = $this->makeOrganization();
        $admin = $this->makeUser($org, 'company_admin', $roles);

        $this->actingAs($admin)
            ->put(route('mail-settings.update'), [
                'mail_enabled' => '1',
                'mail_host' => 'smtp.acme.test',
                'mail_port' => '587',
                'mail_encryption' => 'tls',
                'mail_username' => 'mailer@acme.test',
                'mail_password' => 's3cr3t-pass',
                'mail_from_address' => 'noreply@acme.test',
                'mail_from_name' => 'Acme Mail',
            ])
            ->assertRedirect(route('mail-settings.edit'))
            ->assertSessionHas('status');

        $settings = $org->fresh()->settings;

        $this->assertTrue($settings['mail_enabled']);
        $this->assertSame('smtp.acme.test', $settings['mail_host']);
        $this->assertSame(587, $settings['mail_port']);
        $this->assertSame('tls', $settings['mail_encryption']);
        $this->assertSame('mailer@acme.test', $settings['mail_username']);
        $this->assertSame('noreply@acme.test', $settings['mail_from_address']);
        $this->assertNotSame('s3cr3t-pass', $settings['mail_password']);
        $this->assertSame('s3cr3t-pass', decrypt($settings['mail_password']));

        $this->assertDatabaseHas('audit_logs', ['action' => 'mail.settings_updated']);
    }

    public function test_blank_password_keeps_the_stored_one(): void
    {
        ['organization' => $org, 'roles' => $roles] = $this->makeOrganization();
        $admin = $this->makeUser($org, 'company_admin', $roles);

        $payload = [
            'mail_enabled' => '1',
            'mail_host' => 'smtp.acme.test',
            'mail_port' => '465',
            'mail_encryption' => 'ssl',
            'mail_username' => 'mailer@acme.test',
            'mail_password' => 'first-secret',
            'mail_from_address' => 'noreply@acme.test',
            'mail_from_name' => 'Acme',
        ];

        $this->actingAs($admin)->put(route('mail-settings.update'), $payload);
        $stored = $org->fresh()->settings['mail_password'];

        $payload['mail_password'] = '';
        $this->actingAs($admin)->put(route('mail-settings.update'), $payload);

        $this->assertSame($stored, $org->fresh()->settings['mail_password']);
    }

    public function test_saving_requires_host_port_and_from_identity(): void
    {
        ['organization' => $org, 'roles' => $roles] = $this->makeOrganization();
        $admin = $this->makeUser($org, 'company_admin', $roles);

        $this->actingAs($admin)
            ->put(route('mail-settings.update'), [
                'mail_enabled' => '1',
                'mail_host' => '',
                'mail_port' => 'abc',
                'mail_encryption' => 'tls',
                'mail_from_address' => 'not-an-email',
                'mail_from_name' => '',
            ])
            ->assertSessionHasErrors(['mail_host', 'mail_port', 'mail_from_address', 'mail_from_name']);
    }

    public function test_apply_overrides_the_runtime_mailer_when_enabled_and_skips_when_disabled(): void
    {
        ['organization' => $org] = $this->makeOrganization();
        $original = config('mail');

        try {
            $baseline = config('mail.default');

            app(MailSettings::class)->apply($org);
            $this->assertSame($baseline, config('mail.default'));

            $org->update(['settings' => [
                'mail_enabled' => true,
                'mail_host' => 'smtp.acme.test',
                'mail_port' => 465,
                'mail_encryption' => 'ssl',
                'mail_username' => 'mailer@acme.test',
                'mail_password' => encrypt('s3cr3t'),
                'mail_from_address' => 'noreply@acme.test',
                'mail_from_name' => 'Acme Corp',
            ]]);

            app(MailSettings::class)->apply($org->fresh());

            $this->assertSame('smtp', config('mail.default'));
            $this->assertSame('smtp.acme.test', config('mail.mailers.smtp.host'));
            $this->assertSame(465, config('mail.mailers.smtp.port'));
            $this->assertSame('smtps', config('mail.mailers.smtp.scheme'));
            $this->assertSame('mailer@acme.test', config('mail.mailers.smtp.username'));
            $this->assertSame('s3cr3t', config('mail.mailers.smtp.password'));
            $this->assertSame('noreply@acme.test', config('mail.from.address'));
            $this->assertSame('Acme Corp', config('mail.from.name'));
        } finally {
            config()->set('mail', $original);
        }
    }

    public function test_encryption_choices_map_to_supported_smtp_schemes(): void
    {
        ['organization' => $org] = $this->makeOrganization();
        $original = config('mail');

        try {
            foreach (['ssl' => 'smtps', 'tls' => 'smtp', 'none' => 'smtp'] as $encryption => $scheme) {
                $org->update(['settings' => [
                    'mail_enabled' => true,
                    'mail_host' => 'smtp.acme.test',
                    'mail_port' => $encryption === 'ssl' ? 465 : 587,
                    'mail_encryption' => $encryption,
                    'mail_from_address' => 'noreply@acme.test',
                    'mail_from_name' => 'Acme',
                ]]);

                app(MailSettings::class)->apply($org->fresh());
                $this->assertSame($scheme, config('mail.mailers.smtp.scheme'));

                // Resolving the mailer builds the Symfony transport — this is
                // exactly where an unsupported scheme (e.g. "ssl") throws
                // UnsupportedSchemeException.
                Mail::forgetMailers();
                Mail::mailer('smtp');
            }
        } finally {
            config()->set('mail', $original);
        }
    }

    public function test_the_sending_listener_applies_org_smtp_before_mail_channel_sends(): void
    {
        ['organization' => $org, 'roles' => $roles] = $this->makeOrganization();
        $admin = $this->makeUser($org, 'company_admin', $roles);
        $org->update(['settings' => [
            'mail_enabled' => true,
            'mail_host' => 'smtp.acme.test',
            'mail_port' => 587,
            'mail_encryption' => 'tls',
            'mail_from_address' => 'noreply@acme.test',
            'mail_from_name' => 'Acme',
        ]]);

        $original = config('mail');

        try {
            Event::dispatch(new NotificationSending($admin, new MailTest, 'mail'));

            $this->assertSame('smtp', config('mail.default'));
            $this->assertSame('smtp.acme.test', config('mail.mailers.smtp.host'));

            // Non-mail channels leave the mailer untouched.
            config()->set('mail.default', 'log');
            Event::dispatch(new NotificationSending($admin, new MailTest, 'database'));
            $this->assertSame('log', config('mail.default'));
        } finally {
            config()->set('mail', $original);
        }
    }

    public function test_test_email_button_notifies_the_admin(): void
    {
        ['organization' => $org, 'roles' => $roles] = $this->makeOrganization();
        $admin = $this->makeUser($org, 'company_admin', $roles);

        $org->update(['settings' => ['mail_host' => 'smtp.acme.test']]);

        Notification::fake();

        $this->actingAs($admin)
            ->post(route('mail-settings.test'))
            ->assertRedirect()
            ->assertSessionHas('status');

        Notification::assertSentTo($admin, MailTest::class);
    }

    public function test_test_email_requires_a_saved_host(): void
    {
        ['organization' => $org, 'roles' => $roles] = $this->makeOrganization();
        $admin = $this->makeUser($org, 'company_admin', $roles);

        $this->actingAs($admin)
            ->post(route('mail-settings.test'))
            ->assertSessionHasErrors('status');
    }

    public function test_mail_settings_are_tenant_scoped(): void
    {
        ['organization' => $orgA, 'roles' => $roles] = $this->makeOrganization('Mail Co A');
        ['organization' => $orgB] = $this->makeOrganization('Mail Co B');
        $adminA = $this->makeUser($orgA, 'company_admin', $roles);

        $this->actingAs($adminA)
            ->put(route('mail-settings.update'), [
                'mail_enabled' => '1',
                'mail_host' => 'smtp.a.test',
                'mail_port' => '587',
                'mail_encryption' => 'tls',
                'mail_username' => '',
                'mail_password' => '',
                'mail_from_address' => 'a@a.test',
                'mail_from_name' => 'A',
            ]);

        $this->assertSame('smtp.a.test', $orgA->fresh()->settings['mail_host'] ?? null);
        $this->assertArrayNotHasKey('mail_host', $orgB->fresh()->settings ?? []);
    }

    public function test_feature_toggles_are_saved_and_audited(): void
    {
        ['organization' => $org, 'roles' => $roles] = $this->makeOrganization();
        $admin = $this->makeUser($org, 'company_admin', $roles);

        $this->actingAs($admin)
            ->put(route('mail-settings.update'), [
                'mail_enabled' => '1',
                'mail_host' => 'smtp.acme.test',
                'mail_port' => '587',
                'mail_encryption' => 'tls',
                'mail_username' => '',
                'mail_password' => '',
                'mail_from_address' => 'noreply@acme.test',
                'mail_from_name' => 'Acme',
                'mail_features' => ['leave', 'salary', 'password_resets'],
            ])
            ->assertRedirect(route('mail-settings.edit'))
            ->assertSessionHas('status');

        $this->assertSame(
            ['leave', 'salary', 'password_resets'],
            $org->fresh()->settings['mail_features'],
        );
        $this->assertDatabaseHas('audit_logs', ['action' => 'mail.settings_updated']);
    }

    public function test_unknown_feature_keys_are_rejected(): void
    {
        ['organization' => $org, 'roles' => $roles] = $this->makeOrganization();
        $admin = $this->makeUser($org, 'company_admin', $roles);

        $this->actingAs($admin)
            ->put(route('mail-settings.update'), [
                'mail_enabled' => '1',
                'mail_host' => 'smtp.acme.test',
                'mail_port' => '587',
                'mail_encryption' => 'tls',
                'mail_from_address' => 'noreply@acme.test',
                'mail_from_name' => 'Acme',
                'mail_features' => ['leave', 'bogus_feature'],
            ])
            ->assertSessionHasErrors('mail_features.1');
    }

    public function test_disabled_feature_cancels_only_the_mail_channel(): void
    {
        ['organization' => $org, 'roles' => $roles] = $this->makeOrganization();
        $admin = $this->makeUser($org, 'company_admin', $roles);

        $leave = new LeaveStatusChanged('approved', 'Annual', '2026-03-02', '2026-03-05', 2);

        // Never configured → everything on, listener lets the send proceed.
        $this->assertNull(
            app('events')->until(new NotificationSending($admin, $leave, 'mail')),
        );

        $org->update(['settings' => ['mail_features' => ['salary']]]);
        $admin->unsetRelation('organization');

        // 'leave' disabled → false cancels the mail channel only…
        $this->assertFalse(
            app('events')->until(new NotificationSending($admin, $leave, 'mail')),
        );

        // …the in-app database channel is untouched…
        $this->assertNull(
            app('events')->until(new NotificationSending($admin, $leave, 'database')),
        );

        // …and the connectivity test is never gated by feature toggles.
        $this->assertNull(
            app('events')->until(new NotificationSending($admin, new MailTest, 'mail')),
        );
    }

    public function test_feature_keys_resolve_per_notification(): void
    {
        $mail = app(MailSettings::class);

        $this->assertSame('leave', $mail->featureKeyFor(new LeaveStatusChanged('approved', 'Annual', '2026-03-02', '2026-03-05', 2)));
        $this->assertSame('leave_notices', $mail->featureKeyFor(new LeaveNoticeAdded(1, 'Annual', 'Dana', 'Approved with note')));
        $this->assertSame('overtime', $mail->featureKeyFor(new OvertimeDetected(1, '2026-03-02', 'Late shift', 'staged')));
        $this->assertSame('risk', $mail->featureKeyFor(new HighRiskAttendance(1, 'Dana', '3 late arrivals', 3, 'high', 'employee')));
        $this->assertSame('password_resets', $mail->featureKeyFor(new ResetPassword('token')));
        $this->assertNull($mail->featureKeyFor(new MailTest));
    }

    public function test_resolved_features_default_to_all_on_and_honor_saved_subsets(): void
    {
        ['organization' => $org] = $this->makeOrganization();

        $defaults = app(MailSettings::class)->resolvedFeatures($org);
        $this->assertNotEmpty($defaults);
        $this->assertCount(count(MailSettings::FEATURES), $defaults);

        foreach (array_keys(MailSettings::FEATURES) as $key) {
            $this->assertTrue($defaults[$key], "Feature [{$key}] should default to on.");
        }

        $org->update(['settings' => ['mail_features' => ['leave']]]);
        $map = app(MailSettings::class)->resolvedFeatures($org->fresh());

        $this->assertTrue($map['leave']);
        $this->assertFalse($map['salary']);
        $this->assertFalse($map['password_resets']);
    }

    public function test_email_settings_page_shows_the_feature_toggles(): void
    {
        ['organization' => $org, 'roles' => $roles] = $this->makeOrganization();
        $admin = $this->makeUser($org, 'company_admin', $roles);

        $this->actingAs($admin)
            ->get(route('mail-settings.edit'))
            ->assertOk()
            ->assertSee('Which emails should be sent?')
            ->assertSee('mail_features[]', false)
            ->assertSee('Warnings & risk alerts')
            ->assertSee('Monthly progress (score & status)');
    }

    public function test_mail_footer_uses_the_organization_name_not_the_app_name(): void
    {
        ['organization' => $org, 'roles' => $roles] = $this->makeOrganization('Acme Inc');
        $user = $this->makeUser($org, 'employee', $roles);

        $mail = (new PasswordChanged)->toMail($user);

        $this->assertSame('Acme Inc', $mail->viewData['footer'] ?? null);
    }

    public function test_mail_footer_prefers_the_admin_saved_from_name_over_the_org_name(): void
    {
        ['organization' => $org, 'roles' => $roles] = $this->makeOrganization('Acme Inc');
        $user = $this->makeUser($org, 'employee', $roles);

        // The admin typed this company name in Settings → Email ("from name").
        $org->update(['settings' => ['mail_from_name' => 'Globex Corporation']]);

        $mail = (new PasswordChanged)->toMail($user->fresh());

        $this->assertSame('Globex Corporation', $mail->viewData['footer'] ?? null);
    }

    public function test_brand_falls_back_to_app_name_for_org_less_recipients(): void
    {
        $this->assertSame(config('app.name'), MailSettings::brandFor((object) []));
    }

    public function test_login_alert_subject_has_no_platform_brand(): void
    {
        ['organization' => $org, 'roles' => $roles] = $this->makeOrganization('Acme Inc');
        $user = $this->makeUser($org, 'employee', $roles);
        $entry = UserLoginHistory::query()->create([
            'organization_id' => $org->id,
            'user_id' => $user->id,
            'ip_address' => '203.0.113.7',
            'login_status' => 'SUCCESS',
        ]);

        $subject = (new LoginAlert($entry))->subject();

        $this->assertSame('New sign-in to your account', $subject);
        $this->assertStringNotContainsString('EmployeeSaaS', $subject);
    }

    public function test_password_reset_email_salutation_uses_the_admin_or_org_name(): void
    {
        ['organization' => $org, 'roles' => $roles] = $this->makeOrganization('Acme Inc');
        $user = $this->makeUser($org, 'employee', $roles, ['password' => 'password']);

        // No mail_from_name saved — fall back to the organization name.
        $mail = (new ResetPassword('token'))->toMail($user);

        $this->assertStringContainsString('Acme Inc', (string) $mail->salutation);
        $this->assertStringNotContainsString('EmployeeSaaS', (string) $mail->salutation);
        $this->assertStringContainsString('/reset-password/token', $mail->actionUrl);

        // Admin saved a from-name in Settings → Email — it wins everywhere.
        $org->update(['settings' => ['mail_from_name' => 'Globex Corporation']]);

        $mail = (new ResetPassword('token'))->toMail($user->fresh());

        $this->assertStringContainsString('Globex Corporation', (string) $mail->salutation);
    }

    public function test_from_name_defaults_to_the_organization_name_when_unset(): void
    {
        ['organization' => $org] = $this->makeOrganization('Zecovex');

        $org->update(['settings' => [
            'mail_enabled' => true,
            'mail_host' => 'smtp.example.test',
            'mail_port' => 587,
            'mail_encryption' => 'tls',
            'mail_from_address' => 'hr@zecovex.test',
            // no mail_from_name
        ]]);

        app(MailSettings::class)->apply($org->fresh());

        $this->assertSame('Zecovex', config('mail.from.name'));
    }

    public function test_mail_test_body_has_no_hardcoded_platform_name(): void
    {
        $this->assertStringNotContainsString('EmployeeSaaS', implode(' ', (new MailTest)->mailLines()));
    }
}
