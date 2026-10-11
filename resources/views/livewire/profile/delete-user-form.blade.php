<?php

use App\Livewire\Actions\Logout;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Volt\Component;

new class extends Component
{
    public string $password = '';

    /**
     * Delete the currently authenticated user.
     *
     * §43 — self-service account deletion is prohibited for every role.
     * Removal is an admin-controlled workflow (Users page → Delete, or an
     * approved support ticket); deactivation is the reversible first step.
     */
    public function deleteUser(Logout $logout): void
    {
        throw ValidationException::withMessages([
            'password' => 'Self-service account deletion is disabled. Contact an administrator to deactivate or remove your account.',
        ]);
    }
}; ?>

<section class="space-y-6">
    <header>
        <h2 class="text-lg font-medium text-gray-900">
            {{ __('Delete Account') }}
        </h2>

        <p class="mt-1 text-sm text-gray-600">
            {{ __('Account removal is administrator-controlled. Ask a company admin to deactivate or delete your account from the Users page. You can download your data below first.') }}
        </p>
    </header>

    <div class="flex items-center gap-3">
        <a href="{{ route('profile.export') }}"
           class="inline-flex items-center px-4 py-2 bg-white border border-gray-300 rounded-md text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50">
            {{ __('Download my data (JSON)') }}
        </a>
    </div>
</section>
