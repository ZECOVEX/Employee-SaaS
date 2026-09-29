<?php

namespace App\Notifications;

use App\Models\OvertimeApproval;
use App\Models\OvertimeRecord;

/**
 * §73-E: the employee is told when their overtime is approved or rejected
 * (rejection includes the reviewer's reason).
 */
class OvertimeDecision extends OrganizationNotification
{
    public function __construct(
        private readonly int $employeeId,
        private readonly string $date,
        private readonly string $label,
        private readonly string $decision,
        private readonly ?string $reason,
    ) {}

    public static function forRecord(OvertimeRecord $record, string $decision, ?string $reason = null): self
    {
        return new self(
            (int) $record->employee_id,
            $record->work_date->toDateString(),
            $record->minutesLabel(),
            $decision,
            $reason,
        );
    }

    public function title(): string
    {
        return $this->decision === OvertimeApproval::REJECTED
            ? 'Overtime rejected'
            : 'Overtime approved';
    }

    public function body(): string
    {
        $body = sprintf('%s — %s.', $this->date, $this->label);

        if ($this->decision === OvertimeApproval::REJECTED && $this->reason !== null) {
            $body .= ' Reason: '.$this->reason;
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
        $verb = $this->decision === OvertimeApproval::REJECTED ? 'rejected' : 'approved';
        $lines = [sprintf('Your %s of overtime on %s was %s.', $this->label, $this->date, $verb)];

        if ($this->reason !== null) {
            $lines[] = 'Reason: '.$this->reason;
        }

        return $lines;
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
}
