<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\User;
use App\Notifications\AttendanceCorrected;
use App\Notifications\EmploymentStatusChanged;
use App\Notifications\LateArrival;
use App\Notifications\LeaveStatusChanged;
use App\Notifications\MonthlyStatusReady;
use App\Notifications\NfcCardStatusChanged;
use App\Notifications\OvertimeDecision;
use App\Notifications\OvertimeDetected;
use App\Notifications\OvertimeStaged;
use App\Notifications\PasswordChanged;
use App\Notifications\PasswordChangeRequired;
use App\Notifications\SalaryUpdated;
use App\Notifications\SupportTicketCreated;
use Illuminate\Notifications\DatabaseNotification;

/**
 * Where a notification click should land. New rows carry data.url (written
 * by OrganizationNotification::toArray); legacy rows predate that key and
 * are resolved from their type — constant-URL types map directly, and
 * recipient-scoped types (attendance) resolve through the recipient's own
 * employee record. Unresolvable rows fall back to the notification center.
 */
class NotificationDestination
{
    public static function for(DatabaseNotification $notification): ?string
    {
        $stored = $notification->data['url'] ?? null;

        if (is_string($stored) && $stored !== '') {
            return $stored;
        }

        return match ($notification->type) {
            PasswordChanged::class,
            NfcCardStatusChanged::class => route('profile'),
            PasswordChangeRequired::class => route('profile').'#password',
            EmploymentStatusChanged::class => route('dashboard'),
            SalaryUpdated::class,
            MonthlyStatusReady::class => route('statistics.index'),
            SupportTicketCreated::class => route('support.index'),
            OvertimeDecision::class,
            OvertimeDetected::class,
            OvertimeStaged::class => route('overtime.index'),
            LeaveStatusChanged::class => route('leave.index'),
            AttendanceCorrected::class,
            LateArrival::class => self::attendanceUrl($notification),
            default => null,
        };
    }

    private static function attendanceUrl(DatabaseNotification $notification): ?string
    {
        $notifiable = $notification->notifiable;

        if (! $notifiable instanceof User) {
            return null;
        }

        $employee = $notifiable->employee;

        return $employee ? route('attendance.employee', $employee->id) : null;
    }
}
