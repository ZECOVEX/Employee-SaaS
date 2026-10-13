<?php

declare(strict_types=1);

namespace App\Notifications;

/**
 * Post-Phase 17 — "Send test email" from Settings → Email. Mail channel
 * only (no database row); the NotificationSending listener applies the
 * organization's SMTP settings before the send.
 */
class MailTest extends OrganizationNotification
{
    public function title(): string
    {
        return 'Email configuration test';
    }

    public function body(): string
    {
        return 'Test email — your SMTP settings work.';
    }

    public function subject(): string
    {
        return 'Email configuration test';
    }

    public function mailLines(): array
    {
        return [
            'This is a test email from EmployeeSaaS.',
            'Outgoing email (SMTP) settings for this organization are working.',
        ];
    }

    public function group(): string
    {
        return 'security';
    }

    public function featureKey(): ?string
    {
        // Connectivity test — never gated by the feature toggles.
        return null;
    }

    public function actionUrl(): ?string
    {
        return route('mail-settings.edit');
    }

    public function actionText(): string
    {
        return 'Open email settings';
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }
}
