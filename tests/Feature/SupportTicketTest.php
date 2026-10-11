<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\SupportTicket;
use App\Models\User;
use App\Notifications\PasswordChangeRequired;
use App\Notifications\SupportTicketCreated;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Livewire\Volt\Volt;
use Tests\Concerns\CreatesOrganizations;
use Tests\TestCase;

class SupportTicketTest extends TestCase
{
    use CreatesOrganizations, RefreshDatabase;

    public function test_forgot_password_page_offers_email_and_ticket_options(): void
    {
        $this->get(route('password.request'))
            ->assertOk()
            ->assertSeeVolt('pages.auth.forgot-password')
            ->assertSee('Option 1 — Email me a reset link')
            ->assertSee('Option 2 — Create a support ticket');
    }

    public function test_guest_can_file_a_password_reset_ticket_and_admins_are_notified(): void
    {
        Notification::fake();

        ['organization' => $org, 'roles' => $roles] = $this->makeOrganization();
        $admin = $this->makeUser($org, 'company_admin', $roles);
        $employee = $this->makeUser($org, 'employee', $roles, ['email' => 'ticket.person@acme.test']);

        $component = Volt::test('pages.auth.forgot-password')
            ->set('ticket_email', $employee->email)
            ->set('ticket_message', 'I lost access to my mailbox.')
            ->call('createSupportTicket');

        $component->assertHasNoErrors();

        $ticket = SupportTicket::withoutGlobalScopes()->firstOrFail();
        $this->assertSame($org->id, $ticket->organization_id);
        $this->assertSame($employee->id, $ticket->user_id);
        $this->assertSame('Password reset request', $ticket->subject);
        $this->assertSame('I lost access to my mailbox.', $ticket->message);
        $this->assertSame(SupportTicket::STATUS_OPEN, $ticket->status);

        Notification::assertSentTo($admin, SupportTicketCreated::class);
        $this->assertDatabaseHas('audit_logs', [
            'organization_id' => $org->id,
            'action' => 'support.created',
        ]);
    }

    public function test_ticket_requires_a_known_organization_email(): void
    {
        $component = Volt::test('pages.auth.forgot-password')
            ->set('ticket_email', 'nobody@example.test')
            ->call('createSupportTicket');

        $component->assertHasErrors('ticket_email');

        $this->assertSame(0, SupportTicket::withoutGlobalScopes()->count());
    }

    public function test_support_section_is_restricted_to_admins_managers_and_department_managers(): void
    {
        ['organization' => $org, 'roles' => $roles] = $this->makeOrganization();
        $admin = $this->makeUser($org, 'company_admin', $roles);
        $manager = $this->makeUser($org, 'manager', $roles);
        $hr = $this->makeUser($org, 'hr', $roles);
        $employee = $this->makeUser($org, 'employee', $roles);

        // Guest is bounced to login.
        $this->get(route('support.index'))->assertRedirect(route('login'));

        $this->actingAs($employee)->get(route('support.index'))->assertForbidden();
        $this->actingAs($hr)->get(route('support.index'))->assertForbidden();

        $this->actingAs($manager)->get(route('support.index'))->assertOk();
        $this->actingAs($admin)->get(route('support.index'))->assertOk();

        // An HR user who actually manages a department also sees the queue
        // (departments.manager_id branch of Gate::support.view).
        $department = $this->makeDepartment($org, 'People Ops');
        $department->update(['manager_id' => $hr->id]);

        $this->actingAs($hr->fresh())->get(route('support.index'))->assertOk();
    }

    public function test_tickets_are_tenant_isolated(): void
    {
        ['organization' => $orgA, 'roles' => $rolesA] = $this->makeOrganization('Alpha Corp');
        $adminA = $this->makeUser($orgA, 'company_admin', $rolesA);
        $requesterA = $this->makeUser($orgA, 'employee', $rolesA, ['email' => 'alpha.person@acme.test']);

        ['organization' => $orgB, 'roles' => $rolesB] = $this->makeOrganization('Beta Corp');
        $adminB = $this->makeUser($orgB, 'company_admin', $rolesB);

        $ticketA = $this->makeTicket($orgA, $requesterA);

        $this->actingAs($adminB)->get(route('support.show', $ticketA))->assertNotFound();
        $this->actingAs($adminB)
            ->post(route('support.resolve', $ticketA))
            ->assertNotFound();

        $this->actingAs($adminB)
            ->get(route('support.index'))
            ->assertOk()
            ->assertDontSee('alpha.person@acme.test');

        $this->actingAs($adminA)
            ->get(route('support.index'))
            ->assertOk()
            ->assertSee('alpha.person@acme.test');
    }

    public function test_admin_can_reset_a_users_password_from_the_users_page(): void
    {
        Notification::fake();

        ['organization' => $org, 'roles' => $roles] = $this->makeOrganization();
        $admin = $this->makeUser($org, 'company_admin', $roles);
        $target = $this->makeUser($org, 'employee', $roles, ['email' => 'reset.me@acme.test']);

        $this->actingAs($admin)
            ->post(route('users.reset_password', $target))
            ->assertRedirect(route('users.index'))
            ->assertSessionHas('temp_password');

        $password = session('temp_password');
        $this->assertIsString($password);
        $this->assertSame(8, strlen($password));

        $target->refresh();
        $this->assertTrue(Hash::check($password, $target->password));
        $this->assertTrue($target->must_change_password);

        Notification::assertSentTo($target, PasswordChangeRequired::class);

        $log = AuditLog::where('organization_id', $org->id)
            ->where('action', 'user.password_reset')
            ->where('actor_user_id', $admin->id)
            ->firstOrFail();
        $this->assertSame('(redacted)', $log->new_value['password']);

        // Self-reset is blocked.
        $this->actingAs($admin)
            ->post(route('users.reset_password', $admin))
            ->assertStatus(422);
    }

    public function test_manager_can_reset_the_requesters_password_from_the_ticket(): void
    {
        ['organization' => $org, 'roles' => $roles] = $this->makeOrganization();
        $manager = $this->makeUser($org, 'manager', $roles);
        $requester = $this->makeUser($org, 'employee', $roles, ['email' => 'forgot@acme.test']);

        $ticket = $this->makeTicket($org, $requester);

        $this->actingAs($manager)
            ->post(route('support.reset_password', $ticket))
            ->assertRedirect(route('support.show', $ticket))
            ->assertSessionHas('temp_password');

        $password = session('temp_password');
        $this->assertTrue(Hash::check($password, $requester->fresh()->password));
        $this->assertTrue($requester->fresh()->must_change_password);

        $ticket->refresh();
        $this->assertSame(SupportTicket::STATUS_RESOLVED, $ticket->status);
        $this->assertSame($manager->id, $ticket->resolved_by);
        $this->assertNotNull($ticket->resolved_at);

        // The redirect target must render — it is also where the one-time
        // temp password is displayed to the actor.
        $this->actingAs($manager)
            ->get(route('support.show', $ticket))
            ->assertOk()
            ->assertSee($password)
            ->assertSee('Resolved');

        $this->assertTrue(
            AuditLog::where('organization_id', $org->id)
                ->where('action', 'support.resolved')
                ->where('actor_user_id', $manager->id)
                ->exists()
        );
    }

    public function test_staff_can_reply_and_resolve_without_a_password_reset(): void
    {
        ['organization' => $org, 'roles' => $roles] = $this->makeOrganization();
        $admin = $this->makeUser($org, 'company_admin', $roles);
        $requester = $this->makeUser($org, 'employee', $roles, ['email' => 'reply.me@acme.test']);

        $ticket = $this->makeTicket($org, $requester);

        $this->actingAs($admin)
            ->post(route('support.reply', $ticket), ['reply' => 'We emailed you a reset link instead.'])
            ->assertRedirect(route('support.show', $ticket));

        $this->assertSame('We emailed you a reset link instead.', $ticket->fresh()->reply);
        $this->assertTrue(
            AuditLog::where('organization_id', $org->id)
                ->where('action', 'support.replied')
                ->where('actor_user_id', $admin->id)
                ->exists()
        );

        $this->actingAs($admin)
            ->post(route('support.resolve', $ticket))
            ->assertRedirect(route('support.show', $ticket));

        $ticket->refresh();
        $this->assertSame(SupportTicket::STATUS_RESOLVED, $ticket->status);
        $this->assertSame($admin->id, $ticket->resolved_by);

        // Show page renders with the resolved_at cast intact.
        $this->actingAs($admin)
            ->get(route('support.show', $ticket))
            ->assertOk()
            ->assertSee('Resolved');
    }

    public function test_new_users_must_change_their_password_until_done(): void
    {
        Notification::fake();

        ['organization' => $org, 'roles' => $roles] = $this->makeOrganization();
        $admin = $this->makeUser($org, 'company_admin', $roles);

        $this->actingAs($admin)->post(route('users.store'), [
            'name' => 'New Person',
            'email' => 'new.person@acme.test',
            'password' => 'password',
            'password_confirmation' => 'password',
            'role_id' => $roles['employee']->id,
        ])->assertRedirect(route('users.index'));

        $newUser = User::where('email', 'new.person@acme.test')->firstOrFail();
        $this->assertTrue($newUser->must_change_password);
        Notification::assertSentTo($newUser, PasswordChangeRequired::class);

        // The banner stays on every page until the password changes.
        $this->actingAs($newUser)
            ->get(route('profile'))
            ->assertOk()
            ->assertSee('Please change your password');

        $this->actingAs($newUser);
        Volt::test('profile.update-password-form')
            ->set('current_password', 'password')
            ->set('password', 'brand-new-secret')
            ->set('password_confirmation', 'brand-new-secret')
            ->call('updatePassword')
            ->assertHasNoErrors();

        $this->assertFalse($newUser->fresh()->must_change_password);

        $this->actingAs($newUser)
            ->get(route('profile'))
            ->assertOk()
            ->assertDontSee('Please change your password');
    }

    public function test_navigation_shows_support_only_for_ticket_viewers(): void
    {
        ['organization' => $org, 'roles' => $roles] = $this->makeOrganization();
        $admin = $this->makeUser($org, 'company_admin', $roles);
        $employee = $this->makeUser($org, 'employee', $roles);

        $href = route('support.index');

        $this->actingAs($admin)
            ->get(route('dashboard.admin'))
            ->assertOk()
            ->assertSee($href);

        $this->actingAs($employee)
            ->get(route('dashboard.employee'))
            ->assertOk()
            ->assertDontSee($href);
    }

    private function makeTicket(Organization $organization, User $requester, array $attrs = []): SupportTicket
    {
        return SupportTicket::create(array_merge([
            'organization_id' => $organization->id,
            'user_id' => $requester->id,
            'email' => $requester->email,
            'subject' => 'Password reset request',
        ], $attrs));
    }
}
