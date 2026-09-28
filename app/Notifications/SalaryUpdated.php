<?php

namespace App\Notifications;

class SalaryUpdated extends OrganizationNotification
{
    public function __construct(private readonly string $effectiveFrom) {}

    public function title(): string
    {
        return 'Salary updated';
    }

    public function body(): string
    {
        return 'New record effective '.$this->effectiveFrom.'.';
    }

    public function subject(): string
    {
        return 'Your salary record was updated';
    }

    public function mailLines(): array
    {
        return [
            'A new salary record for your position takes effect '.$this->effectiveFrom.'.',
            'Sign in to review your salary page and monthly statistics.',
        ];
    }

    public function group(): string
    {
        return 'salary';
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
