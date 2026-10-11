<?php

use App\Models\User;
use App\Services\SupportTicketService;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.guest')] class extends Component
{
    public string $email = '';

    public string $ticket_email = '';

    public string $ticket_message = '';

    /**
     * Send a password reset link to the provided email address.
     */
    public function sendPasswordResetLink(): void
    {
        $this->validate([
            'email' => ['required', 'string', 'email'],
        ]);

        // We will send the password reset link to this user. Once we have attempted
        // to send the link, we will examine the response then see the message we
        // need to show to the user. Finally, we'll send out a proper response.
        $status = Password::sendResetLink(
            $this->only('email')
        );

        if ($status != Password::RESET_LINK_SENT) {
            $this->addError('email', __($status));

            return;
        }

        $this->reset('email');

        session()->flash('status', __($status));
    }

    /**
     * Option 2: file a password-recovery support ticket instead of relying
     * on email. Admins + department managers are notified and follow up by
     * issuing a temporary password from the Support section.
     */
    public function createSupportTicket(SupportTicketService $tickets): void
    {
        $this->validate([
            'ticket_email' => [
                'required', 'string', 'email', 'max:190',
                Rule::exists('users', 'email')->whereNotNull('organization_id'),
            ],
            'ticket_message' => ['nullable', 'string', 'max:2000'],
        ]);

        $requester = User::where('email', $this->ticket_email)->firstOrFail();

        $ticket = $tickets->file(
            $requester,
            $this->ticket_email,
            trim($this->ticket_message) ?: null,
        );

        $this->reset('ticket_email', 'ticket_message');

        session()->flash('ticket_status', 'Support ticket #'.$ticket->id.' created. An admin or your department manager will follow up with you.');
    }
}; ?>

<div>
    <div class="mb-4 text-sm text-gray-600">
        {{ __('Forgot your password? Choose how you would like to recover access.') }}
    </div>

    <!-- Session Status -->
    <x-auth-session-status class="mb-4" :status="session('status')" />

    @if (session('ticket_status'))
        <div class="mb-4 rounded-md bg-green-50 p-4 text-sm text-green-800">
            {{ session('ticket_status') }}
        </div>
    @endif

    <!-- Option 1 — email reset link -->
    <div class="rounded-lg border border-gray-200 p-4 mb-4">
        <h3 class="text-sm font-semibold text-gray-900">Option 1 — Email me a reset link</h3>
        <p class="mt-1 mb-3 text-xs text-gray-500">We will email you a link that lets you choose a new password (expires in 60 minutes).</p>

        <form wire:submit="sendPasswordResetLink">
            <!-- Email Address -->
            <div>
                <x-input-label for="email" :value="__('Email')" />
                <x-text-input wire:model="email" id="email" class="block mt-1 w-full" type="email" name="email" required autofocus />
                <x-input-error :messages="$errors->get('email')" class="mt-2" />
            </div>

            <div class="flex items-center justify-end mt-4">
                <x-primary-button>
                    {{ __('Email Password Reset Link') }}
                </x-primary-button>
            </div>
        </form>
    </div>

    <!-- Option 2 — support ticket -->
    <div class="rounded-lg border border-gray-200 p-4">
        <h3 class="text-sm font-semibold text-gray-900">Option 2 — Create a support ticket</h3>
        <p class="mt-1 mb-3 text-xs text-gray-500">No email access? An admin or your department manager will see your ticket and reset your password for you.</p>

        <form wire:submit="createSupportTicket">
            <div>
                <x-input-label for="ticket_email" value="Email" />
                <x-text-input wire:model="ticket_email" id="ticket_email" class="block mt-1 w-full" type="email" name="ticket_email" required />
                <x-input-error :messages="$errors->get('ticket_email')" class="mt-2" />
            </div>

            <div class="mt-4">
                <x-input-label for="ticket_message" value="Message (optional)" />
                <textarea wire:model="ticket_message" id="ticket_message" name="ticket_message" rows="3"
                          class="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm"
                          placeholder="Anything the admin should know…"></textarea>
                <x-input-error :messages="$errors->get('ticket_message')" class="mt-2" />
            </div>

            <div class="flex items-center justify-end mt-4">
                <x-primary-button>
                    {{ __('Create Support Ticket') }}
                </x-primary-button>
            </div>
        </form>
    </div>
</div>
