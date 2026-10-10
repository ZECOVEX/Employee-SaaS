<?php

namespace App\Notifications;

/**
 * Payroll notification (Phase 7): the employee is told when a payslip is
 * finalized, and again when a corrected revision supersedes it.
 */
class PayslipReady extends OrganizationNotification
{
    public function __construct(
        private readonly string $periodLabel,
        private readonly string $netSalary,
        private readonly int $revision,
        private readonly int $employeeId,
        private readonly int $payslipId,
        private readonly bool $revised = false,
    ) {}

    public function title(): string
    {
        return $this->revised ? 'Payslip revised' : 'Payslip ready';
    }

    public function body(): string
    {
        return sprintf(
            '%s payslip %s — net %s.',
            $this->periodLabel,
            $this->revised ? 'revision '.$this->revision.' issued' : 'finalized',
            $this->netSalary,
        );
    }

    public function subject(): string
    {
        return $this->revised
            ? 'Your payslip was revised'
            : 'Your payslip is ready';
    }

    public function mailLines(): array
    {
        return [
            $this->revised
                ? 'A corrected revision of your '.$this->periodLabel.' payslip has been issued.'
                : 'Your '.$this->periodLabel.' payslip has been finalized.',
            'Net pay: '.$this->netSalary.'.',
            'Sign in to view the payslip and its revision history.',
        ];
    }

    public function group(): string
    {
        return 'salary';
    }

    public function actionUrl(): ?string
    {
        return route('payslips.show', [$this->employeeId, $this->payslipId]);
    }

    public function actionText(): string
    {
        return 'View payslip';
    }
}
