<?php

declare(strict_types=1);

namespace App\Notifications;

/**
 * §15 weekly late-risk: the configured late-day threshold was reached in
 * the configured window. Title carries the configured risk level label.
 */
class HighRiskAttendance extends OrganizationNotification
{
    public function __construct(
        private readonly int $employeeId,
        private readonly string $employeeName,
        private readonly string $detail,
        private readonly int $threshold,
        private readonly string $level,
        private readonly string $audience, // employee | manager | hr
    ) {}

    public function title(): string
    {
        return $this->level;
    }

    public function body(): string
    {
        if ($this->audience === 'employee') {
            return $this->detail
                .'. Your attendance pattern has reached the configured late-arrival risk threshold.';
        }

        return sprintf(
            '%s: %s (configured threshold %d).',
            $this->employeeName,
            $this->detail,
            $this->threshold,
        );
    }

    public function subject(): string
    {
        return 'High-risk attendance alert';
    }

    /** @return list<string> */
    public function mailLines(): array
    {
        return [
            $this->body(),
            'Risk thresholds and recipients are configurable under Settings → Scoring & risk.',
        ];
    }

    public function group(): string
    {
        return 'attendance';
    }

    public function actionUrl(): ?string
    {
        return $this->audience === 'employee'
            ? route('statistics.index')
            : route('attendance.employee', $this->employeeId);
    }

    public function actionText(): string
    {
        return $this->audience === 'employee' ? 'View my statistics' : 'View attendance';
    }
}
