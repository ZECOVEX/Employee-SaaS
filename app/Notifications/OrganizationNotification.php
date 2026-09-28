<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Every organization notification ships on both §25 channels (in-app
 * database row + email). The shared email body is rendered from the
 * `mail/notification` template.
 */
abstract class OrganizationNotification extends Notification
{
    use Queueable;

    /** Short human title shown in the in-app notification center. */
    abstract public function title(): string;

    /** One-line detail for the in-app list. */
    abstract public function body(): string;

    /** Email subject line. */
    abstract public function subject(): string;

    /** @return list<string> Email body lines. */
    abstract public function mailLines(): array;

    /** Group key: leave | attendance | security | salary | nfc. */
    abstract public function group(): string;

    /** Optional CTA target; null renders the email without a button. */
    public function actionUrl(): ?string
    {
        return null;
    }

    public function actionText(): string
    {
        return 'Open app';
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    /**
     * @return array{title: string, body: string, group: string}
     */
    public function toArray(object $notifiable): array
    {
        return [
            'title' => $this->title(),
            'body' => $this->body(),
            'group' => $this->group(),
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $data = [
            'name' => $notifiable->name,
            'subject' => $this->subject(),
            'lines' => $this->mailLines(),
            'footer' => config('app.name'),
        ];

        if ($this->actionUrl() !== null) {
            $data['actionText'] = $this->actionText();
            $data['actionUrl'] = $this->actionUrl();
        }

        return (new MailMessage)
            ->subject($this->subject())
            ->markdown('mail.notification', $data);
    }
}
