<?php

namespace App\Notifications;

class AttendanceCorrected extends OrganizationNotification
{
    public function __construct(
        private readonly int $employeeId,
        private readonly string $date,
        private readonly string $summary,
        private readonly string $reason,
    ) {}

    public function title(): string
    {
        return 'Attendance corrected';
    }

    public function body(): string
    {
        return $this->date.' — '.$this->summary;
    }

    public function subject(): string
    {
        return 'Your attendance record was corrected';
    }

    public function mailLines(): array
    {
        return [
            'An administrator corrected your attendance record for '.$this->date.':',
            $this->summary,
            'Reason: '.$this->reason,
            'The original entry stays in the audit trail (§24).',
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
