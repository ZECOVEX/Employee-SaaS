<?php

namespace Tests\Feature;

use App\Models\AttendanceEvent;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\NfcCard;
use App\Models\Organization;
use App\Models\User;
use App\Notifications\AttendanceCorrected;
use App\Notifications\LateArrival;
use App\Notifications\LeaveStatusChanged;
use App\Notifications\NfcCardStatusChanged;
use App\Notifications\PasswordChanged;
use App\Notifications\SalaryUpdated;
use App\Services\AttendanceService;
use App\Services\LeaveService;
use App\Services\WorkforceDefaults;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Volt\Volt;
use Tests\Concerns\CreatesOrganizations;
use Tests\TestCase;

class NotificationTest extends TestCase
{
    use CreatesOrganizations;
    use RefreshDatabase;

    /**
     * Org + defaults + admin (actor) + employee "Nina Notify".
     *
     * @return array{0: Organization, 1: User, 2: User, 3: Employee}
     */
    private function bootNotifications(string $name): array
    {
        $ctx = $this->makeOrganization($name);
        app(WorkforceDefaults::class)->apply($ctx['organization']);

        $admin = $this->makeUser($ctx['organization'], 'company_admin', $ctx['roles']);
        $user = $this->makeUser($ctx['organization'], 'employee', $ctx['roles'], ['name' => 'Nina Notify']);
        $employee = $this->makeEmployee($ctx['organization'], $user, 'EMP-N1');

        return [$ctx['organization'], $admin, $user, $employee];
    }

    private function pendingLeave(Employee $employee, string $code, string $start, string $end): LeaveRequest
    {
        $type = LeaveType::withoutGlobalScopes()
            ->where('organization_id', $employee->organization_id)
            ->where('code', $code)
            ->firstOrFail();

        $request = new LeaveRequest([
            'start_date' => $start,
            'end_date' => $end,
            'days' => Carbon::parse($start)->diffInDays(Carbon::parse($end)) + 1,
            'reason' => 'Trip',
            'status' => LeaveRequest::PENDING,
        ]);
        $request->organization()->associate($employee->organization);
        $request->employee()->associate($employee);
        $request->leaveType()->associate($type);
        $request->save();

        return $request;
    }

    public function test_leave_approval_and_rejection_notify_the_employee_on_both_channels(): void
    {
        Notification::fake();
        [, $admin, $user, $employee] = $this->bootNotifications('NotifyLeave');
        $this->actingAs($admin); // org scope needs an authenticated tenant user

        $approved = $this->pendingLeave($employee, 'ANNUAL', '2026-10-12', '2026-10-14');
        app(LeaveService::class)->approve($approved, $admin->id, 'Enjoy the trip');

        Notification::assertSentTo(
            $user,
            LeaveStatusChanged::class,
            fn ($notification, $channels) => $channels === ['database', 'mail']
                && $notification->toMail($user)->subject === 'Leave request approved',
        );
        Notification::assertNotSentTo($admin, LeaveStatusChanged::class);

        $rejected = $this->pendingLeave($employee, 'SICK', '2026-10-20', '2026-10-20');
        app(LeaveService::class)->reject($rejected, $admin->id, 'Busy season');

        Notification::assertSentTo(
            $user,
            LeaveStatusChanged::class,
            fn ($notification) => $notification->toMail($user)->subject === 'Leave request rejected',
        );
    }

    public function test_attendance_corrections_notify_the_employee_but_not_the_admin(): void
    {
        $this->travelTo(Carbon::parse('2026-09-15 12:00:00'));
        [, $admin, $user, $employee] = $this->bootNotifications('NotifyAtt');
        $service = app(AttendanceService::class);
        $service->recordManual($employee, AttendanceEvent::CHECK_IN, Carbon::parse('2026-09-07 09:00'), 'seed', $user->id);

        $event = AttendanceEvent::withoutGlobalScopes()
            ->where('employee_id', $employee->id)
            ->orderBy('id')
            ->firstOrFail();

        Notification::fake();

        $this->actingAs($admin)
            ->put(route('attendance.events.update', $event), [
                'event_type' => AttendanceEvent::CHECK_IN,
                'occurred_at' => '2026-09-07 09:45',
                'notes' => 'Badge was not read',
            ])
            ->assertSessionHas('status');

        Notification::assertSentTo(
            $user,
            AttendanceCorrected::class,
            fn ($notification, $channels) => $channels === ['database', 'mail']
                && str_contains($notification->toMail($user)->subject, 'attendance record was corrected'),
        );
        Notification::assertNotSentTo($admin, AttendanceCorrected::class);

        // Removing an entry also notifies with the reason (latest active row
        // — the correction superseded the original).
        $replacement = AttendanceEvent::withoutGlobalScopes()
            ->where('employee_id', $employee->id)
            ->whereNull('superseded_by')
            ->whereNull('deleted_at')
            ->orderByDesc('id')
            ->firstOrFail();

        $this->actingAs($admin)
            ->delete(route('attendance.events.destroy', $replacement), ['reason' => 'Duplicate entry'])
            ->assertSessionHas('status');

        Notification::assertSentTo(
            $user,
            AttendanceCorrected::class,
            fn ($notification) => str_contains($notification->body(), 'entry removed'),
        );
    }

    public function test_salary_updates_notify_the_employee(): void
    {
        Notification::fake();
        [, $admin, $user, $employee] = $this->bootNotifications('NotifySal');

        $this->actingAs($admin)
            ->post(route('salary.store', $employee), [
                'basic_salary' => 30000,
                'effective_from' => '2026-11-01',
            ])
            ->assertRedirect(route('salary.show', $employee));

        Notification::assertSentTo(
            $user,
            SalaryUpdated::class,
            fn ($notification, $channels) => $channels === ['database', 'mail']
                && str_contains($notification->body(), '2026-11-01'),
        );
        Notification::assertNotSentTo($admin, SalaryUpdated::class);
    }

    public function test_nfc_card_status_changes_notify_the_assigned_employee(): void
    {
        Notification::fake();
        [$org, $admin, $user, $employee] = $this->bootNotifications('NotifyNfc');

        $card = NfcCard::create([
            'organization_id' => $org->id,
            'card_token' => 'NFC-'.strtoupper('notify123456'),
            'employee_id' => $employee->id,
            'status' => 'active',
            'issued_at' => now(),
        ]);

        $this->actingAs($admin)
            ->post(route('nfc-cards.block', $card))
            ->assertSessionHas('status');

        Notification::assertSentTo(
            $user,
            NfcCardStatusChanged::class,
            fn ($notification, $channels) => $channels === ['database', 'mail']
                && str_contains($notification->body(), 'blocked'),
        );
        Notification::assertNotSentTo($admin, NfcCardStatusChanged::class);
    }

    public function test_late_arrival_notifies_once_per_day(): void
    {
        $this->travelTo(Carbon::parse('2026-09-08 23:00:00')); // Tuesday
        [, , $user, $employee] = $this->bootNotifications('NotifyLate');
        $service = app(AttendanceService::class);

        Notification::fake();

        // 45 minutes late → LATE day.
        $service->recordManual($employee, AttendanceEvent::CHECK_IN, Carbon::parse('2026-09-08 09:45'), 'seed', $user->id);
        Notification::assertSentToTimes($user, LateArrival::class, 1);

        // Checkout re-derives the same day → no duplicate.
        $service->recordManual($employee, AttendanceEvent::CHECK_OUT, Carbon::parse('2026-09-08 18:00'), 'seed', $user->id);
        Notification::assertSentToTimes($user, LateArrival::class, 1);

        // A punctual day on another date never notifies.
        $service->recordManual($employee, AttendanceEvent::CHECK_IN, Carbon::parse('2026-09-07 09:00'), 'seed', $user->id);
        Notification::assertSentToTimes($user, LateArrival::class, 1);
    }

    public function test_password_change_notifies_the_user(): void
    {
        Notification::fake();
        [, , $user] = $this->bootNotifications('NotifyPw');

        $this->actingAs($user);

        Volt::test('profile.update-password-form')
            ->set('current_password', 'password')
            ->set('password', 'NewSecret123!')
            ->set('password_confirmation', 'NewSecret123!')
            ->call('updatePassword')
            ->assertHasNoErrors();

        Notification::assertSentTo(
            $user,
            PasswordChanged::class,
            fn ($notification, $channels) => $channels === ['database', 'mail'],
        );
    }

    public function test_notification_center_lists_and_marks_notifications_read(): void
    {
        [, $admin, $user, $employee] = $this->bootNotifications('NotifyCenter');

        // Real (unfaked) notification from a salary update.
        $this->actingAs($admin)
            ->post(route('salary.store', $employee), [
                'basic_salary' => 30000,
                'effective_from' => '2026-11-01',
            ]);

        $this->actingAs($user)
            ->get(route('notifications.index'))
            ->assertOk()
            ->assertSee('Notifications')
            ->assertSee('Salary updated')
            ->assertSee('1 unread');

        $id = $user->notifications()->firstOrFail()->id;

        // Bell badge appears while something is unread.
        $this->actingAs($user)
            ->get(route('dashboard.employee'))
            ->assertOk()
            ->assertSee('title="Notifications"', false);

        // Clicking marks read (GET, idempotent).
        $this->actingAs($user)
            ->get(route('notifications.read', $id))
            ->assertRedirect();
        $this->assertNotNull($user->notifications()->whereKey($id)->first()->read_at);

        // Mark-all clears the rest.
        $user->notify(new PasswordChanged);
        $this->actingAs($user)
            ->post(route('notifications.readAll'))
            ->assertSessionHas('status');
        $this->assertSame(0, $user->notifications()->whereNull('read_at')->count());
    }

    public function test_notifications_are_scoped_to_the_recipient(): void
    {
        [, $admin, $user, $employee] = $this->bootNotifications('NotifyScope');

        $this->actingAs($admin)
            ->post(route('salary.store', $employee), [
                'basic_salary' => 30000,
                'effective_from' => '2026-11-01',
            ]);

        $id = $user->notifications()->firstOrFail()->id;

        $otherUser = $this->makeUser($employee->organization, 'employee', null, [
            'name' => 'Other Person',
            'email' => 'other-'.uniqid().'@example.test',
        ]);
        $this->makeEmployee($employee->organization, $otherUser, 'EMP-N2');

        // Another account never sees or reads it (404, not 403).
        $this->actingAs($otherUser)
            ->get(route('notifications.index'))
            ->assertOk()
            ->assertDontSee('Salary updated');

        $this->actingAs($otherUser)
            ->get(route('notifications.read', $id))
            ->assertNotFound();

        $this->assertNull($user->notifications()->whereKey($id)->first()->read_at);
    }

    public function test_notification_emails_use_the_shared_template(): void
    {
        [, , $user] = $this->bootNotifications('NotifyMail');

        $notification = new LeaveStatusChanged('approved', 'ANNUAL', '01 Oct 2026', '03 Oct 2026', 3, 'Enjoy');
        $mail = $notification->toMail($user);

        $this->assertSame('Leave request approved', $mail->subject);
        $this->assertSame('mail.notification', $mail->markdown);

        $rendered = view('mail.notification', [
            'name' => $user->name,
            'subject' => $mail->subject,
            'lines' => $notification->mailLines(),
            'footer' => 'EmployeeSaaS',
            'actionText' => 'View leave requests',
            'actionUrl' => 'http://localhost/leave',
        ])->render();

        $this->assertStringContainsString('Hello Nina Notify', $rendered);
        $this->assertStringContainsString('Your leave request was approved', $rendered);
        $this->assertStringContainsString('Reviewer note: Enjoy', $rendered);
        $this->assertStringContainsString('View leave requests', $rendered);
    }
}
