<?php

declare(strict_types=1);

namespace App\Services\Webhooks;

/**
 * Canonical webhook event names emitted by the application. Subscriptions
 * store a list of these (or '*' for everything).
 */
final class WebhookEvent
{
    public const EMPLOYEE_CREATED = 'employee.created';

    public const EMPLOYEE_UPDATED = 'employee.updated';

    public const EMPLOYEE_DELETED = 'employee.deleted';

    public const LEAVE_REQUESTED = 'leave.requested';

    public const LEAVE_APPROVED = 'leave.approved';

    public const LEAVE_REJECTED = 'leave.rejected';

    public const OVERTIME_APPROVED = 'overtime.approved';

    public const OVERTIME_REJECTED = 'overtime.rejected';

    public const ATTENDANCE_PUNCHED = 'attendance.punched';

    public const PAYSLIP_FINALIZED = 'payslip.finalized';

    public const SUBSCRIPTION_CHANGED = 'subscription.changed';

    public const ALL = [
        self::EMPLOYEE_CREATED,
        self::EMPLOYEE_UPDATED,
        self::EMPLOYEE_DELETED,
        self::LEAVE_REQUESTED,
        self::LEAVE_APPROVED,
        self::LEAVE_REJECTED,
        self::OVERTIME_APPROVED,
        self::OVERTIME_REJECTED,
        self::ATTENDANCE_PUNCHED,
        self::PAYSLIP_FINALIZED,
        self::SUBSCRIPTION_CHANGED,
    ];
}
