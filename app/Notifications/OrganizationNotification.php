<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Services\MailSettings;
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

    /**
     * Post-Phase 18 — email feature toggle key (Settings → Email). Defaults
     * to the group; classes sharing a group (e.g. all overtime mails) can
     * override. Return null to always allow the email (e.g. MailTest).
     */
    public function featureKey(): ?string
    {
        return $this->group();
    }

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
     * @return array{title: string, body: string, group: string, url: ?string}
     */
    public function toArray(object $notifiable): array
    {
        return [
            'title' => $this->title(),
            'body' => $this->body(),
            'group' => $this->group(),
            'url' => $this->actionUrl(),
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $data = [
            'name' => $notifiable->name,
            'subject' => $this->subject(),
            'lines' => $this->mailLines(),
            // Post-Phase 22 — footer carries the recipient's organization
            // name ("Zecovex"), not the platform app name.
            'footer' => MailSettings::brandFor($notifiable),
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
