<?php

declare(strict_types=1);

namespace App\Notifications;

/**
 * Post-Phase 21 — 6-digit code for the email-based 2FA method (setup confirm
 * and login challenge). Mail channel only; NEVER gated by the Settings →
 * Email feature toggles — it is login-critical like the connectivity test.
 */
class MfaEmailCode extends OrganizationNotification
{
    public function __construct(
        public readonly string $code,
        public readonly string $context,
    ) {}

    public function title(): string
    {
        return 'Your two-factor code';
    }

    public function body(): string
    {
        return 'Your '.$this->context.' code is '.$this->code.'.';
    }

    public function subject(): string
    {
        return 'Your two-factor code: '.$this->code;
    }

    /** @return list<string> */
    public function mailLines(): array
    {
        return [
            'Your '.$this->context.' code is: '.$this->code,
            'It is valid for 10 minutes and can only be used once.',
            "If you didn't request this email, ignore it and consider securing your account.",
        ];
    }

    public function group(): string
    {
        return 'security';
    }

    public function featureKey(): ?string
    {
        // Login-critical — never gated by the feature toggles (like MailTest).
        return null;
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }
}
