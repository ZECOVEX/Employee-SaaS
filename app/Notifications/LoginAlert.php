<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\UserLoginHistory;

/**
 * Post-Phase 21 — opt-in "new sign-in to your account" alert (users.
 * login_alerts_enabled). Mail only; grouped under security so the org's
 * Settings → Email checklist can toggle it. Carries IP, location and device
 * from the recorded login-history row.
 */
class LoginAlert extends OrganizationNotification
{
    public function __construct(private readonly UserLoginHistory $entry) {}

    public function title(): string
    {
        return 'New sign-in to your account';
    }

    public function body(): string
    {
        return 'Signed in from '.$this->entry->locationLabel().' ('.$this->entry->deviceLabel().').';
    }

    public function subject(): string
    {
        return 'New sign-in to your account';
    }

    /** @return list<string> */
    public function mailLines(): array
    {
        return [
            'A new sign-in to your account was detected:',
            'When: '.($this->entry->created_at ?? now())->format('Y-m-d H:i:s T'),
            'IP address: '.($this->entry->ip_address ?? '—'),
            'Location: '.$this->entry->locationLabel(),
            'Device: '.$this->entry->deviceLabel(),
            "If this wasn't you, change your password and review your security settings immediately.",
        ];
    }

    public function group(): string
    {
        return 'security';
    }

    public function actionUrl(): ?string
    {
        return route('profile');
    }

    public function actionText(): string
    {
        return 'Review security settings';
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }
}
