<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Organization;
use App\Notifications\MailTest;
use App\Notifications\OrganizationNotification;
use Illuminate\Auth\Notifications\ResetPassword;

/**
 * Post-Phase 17 — per-org outgoing email (SMTP) settings. Stored in
 * organization.settings under mail_* keys (password encrypted at rest).
 * When enabled, apply() overrides the runtime mail config so every
 * mail-channel notification (including queued sends and Laravel's
 * default ResetPassword) goes through the organization's own SMTP
 * server ("webmail"). Applied automatically by the NotificationSending
 * listener registered in AppServiceProvider.
 *
 * Post-Phase 18 — admins also pick WHICH features may email at all
 * (mail_features: array of enabled keys; unset = everything on). The
 * same listener cancels the mail channel for disabled features while
 * keeping the in-app database notification.
 */
class MailSettings
{
    /** @var list<string> */
    public const KEYS = [
        'mail_enabled',
        'mail_host',
        'mail_port',
        'mail_encryption',
        'mail_username',
        'mail_password',
        'mail_from_address',
        'mail_from_name',
        'mail_features',
    ];

    /** @var array<string, string> feature key => admin-facing label */
    public const FEATURES = [
        'leave' => 'Leave decisions',
        'leave_notices' => 'Leave notices & reviewer notes',
        'attendance' => 'Attendance corrections & late arrivals',
        'overtime' => 'Overtime (detected, staged, decisions)',
        'salary' => 'Salary updates & payslips',
        'monthly_progress' => 'Monthly progress (score & status)',
        'risk' => 'Warnings & risk alerts',
        'employment' => 'Employment status changes',
        'security' => 'Security alerts',
        'nfc' => 'NFC card changes',
        'support' => 'Support tickets',
        'password_resets' => 'Password reset emails',
    ];

    public function apply(Organization $organization): void
    {
        $settings = $organization->settingsArray();

        if (empty($settings['mail_enabled']) || empty($settings['mail_host'])) {
            return;
        }

        $encryption = (string) ($settings['mail_encryption'] ?? 'tls');

        // Symfony's SMTP transport only accepts the "smtp" (STARTTLS) and
        // "smtps" (implicit TLS) DSN schemes — "ssl" is not a scheme.
        $scheme = $encryption === 'ssl' ? 'smtps' : 'smtp';

        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp.transport' => 'smtp',
            'mail.mailers.smtp.scheme' => $scheme,
            'mail.mailers.smtp.host' => (string) $settings['mail_host'],
            'mail.mailers.smtp.port' => (int) ($settings['mail_port'] ?? 587),
            'mail.mailers.smtp.username' => ($settings['mail_username'] ?? '') !== '' ? (string) $settings['mail_username'] : null,
            'mail.mailers.smtp.password' => ! empty($settings['mail_password']) ? (string) decrypt((string) $settings['mail_password']) : null,
            'mail.mailers.smtp.timeout' => 15,
            'mail.from.address' => ! empty($settings['mail_from_address']) ? (string) $settings['mail_from_address'] : config('mail.from.address'),
            // No custom from-name: brand the mail with the organization's own
            // name ("Zecovex") instead of the platform app name.
            'mail.from.name' => ! empty($settings['mail_from_name']) ? (string) $settings['mail_from_name'] : ($organization->name ?: config('mail.from.name')),
        ]);
    }

    /**
     * Display brand for outbound mail. Priority:
     *  1. the "from name" the admin saved in Settings → Email
     *     (organizations.settings.mail_from_name) — whatever company name
     *     they typed in the form;
     *  2. the organization's own name (organizations.name);
     *  3. the platform app name — only for org-less platform admins.
     */
    public static function brandFor(mixed $notifiable): string
    {
        $organization = data_get($notifiable, 'organization');

        if ($organization instanceof Organization) {
            $fromName = $organization->settingsArray()['mail_from_name'] ?? null;

            if (is_string($fromName) && trim($fromName) !== '') {
                return trim($fromName);
            }

            if (filled($organization->name)) {
                return (string) $organization->name;
            }
        }

        return (string) config('app.name');
    }

    /** May the organization email for this feature? Unset map = everything on. */
    public function featureEnabled(Organization $organization, string $key): bool
    {
        $features = $organization->settingsArray()['mail_features'] ?? null;

        if ($features === null) {
            return true;
        }

        return in_array($key, $features, true);
    }

    /**
     * Resolved per-feature on/off map for the settings form.
     *
     * @return array<string, bool>
     */
    public function resolvedFeatures(Organization $organization): array
    {
        $features = $organization->settingsArray()['mail_features'] ?? null;
        $map = [];

        foreach (self::FEATURES as $key => $label) {
            $map[$key] = $features === null || in_array($key, $features, true);
        }

        return $map;
    }

    /**
     * Feature key for a notification instance; null = always allow
     * (MailTest, unknown notification types).
     */
    public function featureKeyFor(object $notification): ?string
    {
        if ($notification instanceof MailTest) {
            return null;
        }

        if ($notification instanceof ResetPassword) {
            return 'password_resets';
        }

        if ($notification instanceof OrganizationNotification) {
            return $notification->featureKey();
        }

        return null;
    }
}
