<?php

namespace App\Services;

use App\Models\Department;
use App\Models\SupportTicket;
use App\Models\User;
use App\Notifications\SupportTicketCreated;
use App\Support\Tenancy\OrganizationScope;

/**
 * Files password-recovery support tickets from the guest forgot-password
 * page and pings every admin + department manager in the requester's org.
 */
class SupportTicketService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Create a ticket for an existing org user and notify stakeholders.
     * The caller has already validated the email against the users table.
     */
    public function file(User $requester, string $email, ?string $message): SupportTicket
    {
        $ticket = SupportTicket::create([
            'organization_id' => $requester->organization_id,
            'user_id' => $requester->id,
            'email' => $email,
            'subject' => 'Password reset request',
            'message' => $message,
        ]);

        $this->audit->log('support.created', $ticket, null, [
            'email' => $email,
            'subject' => 'Password reset request',
        ]);

        $this->notifyStakeholders($ticket);

        return $ticket;
    }

    /**
     * Admins, managers, and whoever manages a department in the org.
     * Runs unauthenticated, so the org scope is bypassed in favour of an
     * explicit organization_id filter.
     *
     * @return iterable<User>
     */
    private function notifyStakeholders(SupportTicket $ticket): iterable
    {
        $organizationId = $ticket->organization_id;

        $managerIds = Department::query()
            ->withoutGlobalScope(OrganizationScope::class)
            ->where('organization_id', $organizationId)
            ->pluck('manager_id')
            ->filter();

        return User::where('organization_id', $organizationId)
            ->where(fn ($q) => $q
                // Role carries its own org scope which sees "no user" in this
                // guest context (1 = 0) — bypass it and filter by org explicitly.
                ->whereHas('roles', fn ($r) => $r
                    ->withoutGlobalScope(OrganizationScope::class)
                    ->whereIn('slug', ['company_admin', 'manager']))
                ->orWhereIn('id', $managerIds))
            ->get()
            ->each(fn (User $user) => $user->notify(new SupportTicketCreated($ticket)));
    }
}
