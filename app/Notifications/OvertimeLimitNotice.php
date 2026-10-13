<?php

declare(strict_types=1);

namespace App\Notifications;

/**
 * §15 [OT] overtime overwork notice (optional, default off): informational
 * compliance signal when a configured weekly/monthly/streak limit is
 * exceeded. It never reduces the employee's score.
 */
class OvertimeLimitNotice extends OrganizationNotification
{
    public function __construct(
        private readonly int $employeeId,
        private readonly string $employeeName,
        private readonly string $level,
        private readonly string $detail,
        private readonly string $limitText,
        private readonly string $audience, // employee | manager | hr
    ) {}

    public function title(): string
    {
        return $this->level;
    }

    public function body(): string
    {
        if ($this->audience === 'employee') {
            return sprintf(
                '%s — you have exceeded the configured %s.',
                $this->detail,
                $this->limitText,
            );
        }

        return sprintf(
            '%s: %s — exceeds the configured %s.',
            $this->employeeName,
            $this->detail,
            $this->limitText,
        );
    }

    public function subject(): string
    {
        return 'Overtime limit notice';
    }

    /** @return list<string> */
    public function mailLines(): array
    {
        return [
            $this->body(),
            'This is an informational compliance signal only — overtime never lowers your attendance score.',
        ];
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
        return $this->audience === 'employee'
            ? route('overtime.index')
            : route('attendance.employee', $this->employeeId);
    }

    public function actionText(): string
    {
        return $this->audience === 'employee' ? 'View my overtime' : 'View attendance';
    }
}
