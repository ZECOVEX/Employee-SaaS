<?php

namespace App\Http\Controllers;

use App\Models\SupportTicket;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\PasswordResetService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * Password-recovery support tickets. Filed by guests from the forgot-password
 * page (see the Volt component + SupportTicketService); this controller is
 * the staff side: list/show, reply, issue a temporary password, resolve.
 * Every action is gated by Gate::support.view (admins + managers +
 * department managers).
 */
class SupportTicketController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(Request $request): View
    {
        Gate::authorize('support.view');

        $status = $request->string('status')->toString();

        $tickets = SupportTicket::with('requester:id,name,email')
            ->when($status !== '', fn ($q) => $q->where('status', $status))
            ->latest()
            ->paginate(15)
            ->withQueryString();

        return view('support.index', compact('tickets', 'status'));
    }

    public function show(SupportTicket $ticket): View
    {
        Gate::authorize('support.view');

        return view('support.show', [
            'ticket' => $ticket->load([
                'requester:id,name,email',
                'resolver:id,name',
            ]),
        ]);
    }

    public function reply(Request $request, SupportTicket $ticket): RedirectResponse
    {
        Gate::authorize('support.view');

        $data = $request->validate([
            'reply' => ['required', 'string', 'max:2000'],
        ]);

        $old = ['reply' => $ticket->reply];
        $ticket->update(['reply' => $data['reply']]);

        $this->audit->log('support.replied', $ticket, $old, ['reply' => $data['reply']]);

        return redirect()
            ->route('support.show', $ticket)
            ->with('status', 'Reply posted.');
    }

    /**
     * Issue a temporary 8-char password for the requester's account and
     * resolve the ticket in one step. The plaintext is flashed for the
     * actor to relay — never stored, never emailed.
     */
    public function resetPassword(Request $request, SupportTicket $ticket): RedirectResponse
    {
        Gate::authorize('support.view');

        $user = $ticket->user_id ? User::find($ticket->user_id) : null;

        abort_unless($user !== null, 422, 'The requester has no user account to reset.');

        $password = app(PasswordResetService::class)->issue($user);

        $this->resolveTicket($ticket, $request->user());

        return redirect()
            ->route('support.show', $ticket)
            ->with('status', "Password reset for {$user->name}. The ticket is resolved.")
            ->with('temp_password', $password);
    }

    public function resolve(Request $request, SupportTicket $ticket): RedirectResponse
    {
        Gate::authorize('support.view');

        $this->resolveTicket($ticket, $request->user());

        return redirect()
            ->route('support.show', $ticket)
            ->with('status', 'Ticket marked resolved.');
    }

    private function resolveTicket(SupportTicket $ticket, User $actor): void
    {
        if ($ticket->status === SupportTicket::STATUS_RESOLVED) {
            return;
        }

        $old = ['status' => $ticket->status];

        $ticket->update([
            'status' => SupportTicket::STATUS_RESOLVED,
            'resolved_by' => $actor->id,
            'resolved_at' => now(),
        ]);

        $this->audit->log('support.resolved', $ticket, $old, [
            'status' => SupportTicket::STATUS_RESOLVED,
        ]);
    }
}
