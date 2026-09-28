<?php

namespace App\Notifications;

class PasswordChanged extends OrganizationNotification
{
    public function title(): string
    {
        return 'Password changed';
    }

    public function body(): string
    {
        return 'Your account password was changed.';
    }

    public function subject(): string
    {
        return 'Your password was changed';
    }

    public function mailLines(): array
    {
        return [
            'Your account password was just changed.',
            'If this was not you, contact your administrator immediately and reset your password.',
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
        return 'Open profile';
    }
}
