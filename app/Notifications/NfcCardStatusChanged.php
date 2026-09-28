<?php

namespace App\Notifications;

class NfcCardStatusChanged extends OrganizationNotification
{
    public function __construct(
        private readonly string $action,
        private readonly string $tokenTail,
    ) {}

    public function title(): string
    {
        return 'NFC card '.$this->action;
    }

    public function body(): string
    {
        return 'Card ending '.$this->tokenTail.' was '.$this->action.'.';
    }

    public function subject(): string
    {
        return 'Your NFC card was '.$this->action;
    }

    public function mailLines(): array
    {
        return [
            'Your NFC card (ending '.$this->tokenTail.') was '.$this->action.'.',
            'If this was not expected, contact your administrator immediately.',
        ];
    }

    public function group(): string
    {
        return 'nfc';
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
