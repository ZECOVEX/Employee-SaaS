<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\SupportTicket;

/**
 * Sent to admins + department managers when a guest files a password-reset
 * ticket from the forgot-password page.
 */
class SupportTicketCreated extends OrganizationNotification
{
    public function __construct(private readonly SupportTicket $ticket) {}

    public function title(): string
    {
        return 'New support ticket';
    }

    public function body(): string
    {
        return $this->ticket->email.' filed "'
            .$this->ticket->subject.'" (ticket #'.$this->ticket->id.').';
    }

    public function subject(): string
    {
        return 'New support ticket #'.$this->ticket->id;
    }

    public function mailLines(): array
    {
        return [
            $this->ticket->email.' requested help with a password reset.',
            'Open the ticket to reply, resolve, or issue a temporary password.',
        ];
    }

    public function group(): string
    {
        return 'security';
    }

    public function featureKey(): ?string
    {
        return 'support';
    }

    public function actionUrl(): ?string
    {
        return route('support.show', $this->ticket);
    }

    public function actionText(): string
    {
        return 'Open ticket';
    }
}
