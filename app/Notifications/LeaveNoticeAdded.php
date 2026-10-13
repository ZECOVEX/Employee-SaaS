<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Support\Str;

class LeaveNoticeAdded extends OrganizationNotification
{
    public function __construct(
        private readonly int $leaveRequestId,
        private readonly string $leaveType,
        private readonly string $authorName,
        private readonly string $preview,
    ) {}

    public function title(): string
    {
        return 'New note on your leave request';
    }

    public function body(): string
    {
        return sprintf(
            '%s posted a note on your %s leave request — %s',
            $this->authorName,
            $this->leaveType,
            Str::limit($this->preview, 120),
        );
    }

    public function subject(): string
    {
        return 'New note on your leave request';
    }

    public function mailLines(): array
    {
        return [
            sprintf('%s posted a note on your %s leave request.', $this->authorName, $this->leaveType),
            '',
            Str::limit($this->preview, 500),
        ];
    }

    public function group(): string
    {
        return 'leave';
    }

    public function featureKey(): ?string
    {
        return 'leave_notices';
    }

    public function actionUrl(): ?string
    {
        return route('leave.show', $this->leaveRequestId);
    }

    public function actionText(): string
    {
        return 'View leave request';
    }
}
