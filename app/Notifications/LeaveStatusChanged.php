<?php

namespace App\Notifications;

class LeaveStatusChanged extends OrganizationNotification
{
    public function __construct(
        private readonly string $decision,
        private readonly string $leaveType,
        private readonly string $startDate,
        private readonly string $endDate,
        private readonly int $days,
        private readonly ?string $note = null,
    ) {}

    public function title(): string
    {
        return 'Leave '.strtolower($this->decision);
    }

    public function body(): string
    {
        $line = sprintf(
            '%s · %s – %s (%d day%s)',
            $this->leaveType,
            $this->startDate,
            $this->endDate,
            $this->days,
            $this->days === 1 ? '' : 's',
        );

        return $this->note ? $line.' — '.$this->note : $line;
    }

    public function subject(): string
    {
        return 'Leave request '.strtolower($this->decision);
    }

    public function mailLines(): array
    {
        $lines = [
            sprintf(
                'Your leave request was %s: %s, %s to %s (%d day%s).',
                strtolower($this->decision),
                $this->leaveType,
                $this->startDate,
                $this->endDate,
                $this->days,
                $this->days === 1 ? '' : 's',
            ),
        ];

        if ($this->note !== null && $this->note !== '') {
            $lines[] = 'Reviewer note: '.$this->note;
        }

        return $lines;
    }

    public function group(): string
    {
        return 'leave';
    }

    public function actionUrl(): ?string
    {
        return route('leave.index');
    }

    public function actionText(): string
    {
        return 'View leave requests';
    }
}
