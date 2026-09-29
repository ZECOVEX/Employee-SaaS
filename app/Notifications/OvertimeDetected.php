<?php

namespace App\Notifications;

use App\Models\OvertimeRecord;

/**
 * §73-I.7 "detected" — extra time was recorded for a day and is waiting for
 * approval (or was auto-approved).
 */
class OvertimeDetected extends OrganizationNotification
{
    public function __construct(
        private readonly int $employeeId,
        private readonly string $date,
        private readonly string $label,
        private readonly string $status,
    ) {}

    public static function forRecord(OvertimeRecord $record): self
    {
        return new self(
            (int) $record->employee_id,
            $record->work_date->toDateString(),
            $record->minutesLabel(),
            $record->status,
        );
    }

    public function title(): string
    {
        return 'Overtime detected';
    }

    public function body(): string
    {
        return sprintf('%s — %s (%s).', $this->date, $this->label, $this->statusLabel());
    }

    public function subject(): string
    {
        return 'Overtime detected';
    }

    public function mailLines(): array
    {
        return [
            sprintf('%s of overtime was recorded on %s.', $this->label, $this->date),
            $this->status === OvertimeRecord::STATUS_PENDING
                ? 'It is pending manager approval and does not count toward pay yet.'
                : 'It has been auto-approved and counts toward your overtime pay.',
        ];
    }

    public function group(): string
    {
        return 'attendance';
    }

    public function actionUrl(): ?string
    {
        return route('overtime.index');
    }

    public function actionText(): string
    {
        return 'View my overtime';
    }

    private function statusLabel(): string
    {
        return $this->status === OvertimeRecord::STATUS_PENDING ? 'pending approval' : 'auto-approved';
    }
}
