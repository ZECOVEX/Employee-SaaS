<?php

declare(strict_types=1);

namespace App\Notifications;

/**
 * Sent when an admin moves an employee's employment status to inactive or
 * terminated. Unlike users.deactivated_at, employment status does not block
 * sign-in — the employee still gets in and instead sees a status badge next
 * to their name plus (when terminated) a red block on their dashboard.
 */
class EmploymentStatusChanged extends OrganizationNotification
{
    public function __construct(private readonly string $status) {}

    public function title(): string
    {
        return $this->status === 'terminated'
            ? 'You have been terminated'
            : 'Your employment status changed';
    }

    public function body(): string
    {
        return $this->status === 'terminated'
            ? 'Your employment has been terminated. Contact HR if you have questions.'
            : 'Your employment status is now inactive. Contact HR if you have questions.';
    }

    public function subject(): string
    {
        return $this->title();
    }

    /** @return list<string> */
    public function mailLines(): array
    {
        return [
            $this->status === 'terminated'
                ? 'Your employment record has been marked as terminated.'
                : 'Your employment record has been marked as inactive.',
            'If you believe this is a mistake, contact your HR department.',
        ];
    }

    public function group(): string
    {
        return 'employment';
    }

    public function actionUrl(): ?string
    {
        return route('dashboard');
    }

    public function actionText(): string
    {
        return 'Open dashboard';
    }
}
