<?php

namespace App\Notifications;

class LateArrival extends OrganizationNotification
{
    public function __construct(
        private readonly int $employeeId,
        private readonly string $date,
        private readonly int $minutes,
    ) {}

    public function title(): string
    {
        return 'Late arrival';
    }

    public function body(): string
    {
        return sprintf('%s — %d minutes late.', $this->date, $this->minutes);
    }

    public function subject(): string
    {
        return 'Late arrival recorded';
    }

    public function mailLines(): array
    {
        return [
            sprintf('You checked in %d minutes late on %s.', $this->minutes, $this->date),
            'Late arrivals can affect salary deductions — check your statistics page for details.',
        ];
    }

    public function group(): string
    {
        return 'attendance';
    }

    public function actionUrl(): ?string
    {
        return route('attendance.employee', $this->employeeId);
    }

    public function actionText(): string
    {
        return 'View my attendance';
    }
}
