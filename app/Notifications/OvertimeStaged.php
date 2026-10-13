<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\OvertimeRecord;

/**
 * §73-E MANAGER_HR: after the manager's first approval the record is
 * still PENDING (HR/Admin confirms next) — the employee is told it was
 * "manager-approved, awaiting HR" rather than the final "approved".
 */
class OvertimeStaged extends OrganizationNotification
{
    public function __construct(
        private readonly int $employeeId,
        private readonly string $date,
        private readonly string $label,
        private readonly ?string $reason,
    ) {}

    public static function forRecord(OvertimeRecord $record, ?string $reason = null): self
    {
        return new self(
            (int) $record->employee_id,
            $record->work_date->toDateString(),
            $record->minutesLabel(),
            $reason,
        );
    }

    public function title(): string
    {
        return 'Overtime manager-approved';
    }

    public function body(): string
    {
        $body = sprintf(
            '%s — %s. Your manager approved it; HR/Admin confirmation is still pending.',
            $this->date,
            $this->label,
        );

        if ($this->reason !== null) {
            $body .= ' Note: '.$this->reason;
        }

        return $body;
    }

    public function subject(): string
    {
        return $this->title();
    }

    /** @return list<string> */
    public function mailLines(): array
    {
        $lines = [sprintf(
            'Your %s of overtime on %s was approved by your manager and now awaits HR/Admin confirmation.',
            $this->label,
            $this->date,
        )];

        if ($this->reason !== null) {
            $lines[] = 'Note: '.$this->reason;
        }

        return $lines;
    }

    public function group(): string
    {
        return 'attendance';
    }

    public function featureKey(): ?string
    {
        return 'overtime';
    }

    public function actionUrl(): ?string
    {
        return route('overtime.index');
    }

    public function actionText(): string
    {
        return 'View my overtime';
    }
}
