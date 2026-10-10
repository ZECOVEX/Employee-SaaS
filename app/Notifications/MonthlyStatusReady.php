<?php

namespace App\Notifications;

/**
 * Month-close notification (Phase 7): once a monthly status snapshot is
 * finalized the employee gets their score and status band.
 */
class MonthlyStatusReady extends OrganizationNotification
{
    public function __construct(
        private readonly string $periodLabel,
        private readonly float $score,
        private readonly string $bandLabel,
        private readonly int $daysPresent,
        private readonly int $daysLate,
    ) {}

    public function title(): string
    {
        return 'Monthly status ready';
    }

    public function body(): string
    {
        return sprintf('%s — score %.1f, %s.', $this->periodLabel, $this->score, $this->bandLabel);
    }

    public function subject(): string
    {
        return 'Your monthly status for '.$this->periodLabel;
    }

    public function mailLines(): array
    {
        return [
            sprintf('Your %s attendance status is finalized: score %.1f (%s).', $this->periodLabel, $this->score, $this->bandLabel),
            sprintf('%d days present, %d late.', $this->daysPresent, $this->daysLate),
            'Sign in to review the full breakdown in Statistics.',
        ];
    }

    public function group(): string
    {
        return 'attendance';
    }

    public function actionUrl(): ?string
    {
        return route('statistics.index');
    }

    public function actionText(): string
    {
        return 'View statistics';
    }
}
