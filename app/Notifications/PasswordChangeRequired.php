<?php

namespace App\Notifications;

/**
 * Sent whenever must_change_password flips on (admin/manager reset or
 * admin-created user). Stays unread until the user changes their password;
 * the app-wide banner mirrors it until the flag clears.
 */
class PasswordChangeRequired extends OrganizationNotification
{
    public function title(): string
    {
        return 'Change your password';
    }

    public function body(): string
    {
        return 'Your account is using a temporary or initial password. Change it now — this notice stays until you do.';
    }

    public function subject(): string
    {
        return 'Please change your password';
    }

    public function mailLines(): array
    {
        return [
            'Your account password was reset or set by an administrator.',
            'Sign in and choose a new password immediately to secure your account.',
        ];
    }

    public function group(): string
    {
        return 'security';
    }

    public function actionUrl(): ?string
    {
        return route('profile').'#password';
    }

    public function actionText(): string
    {
        return 'Change password';
    }
}
