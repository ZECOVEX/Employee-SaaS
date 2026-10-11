<?php

use App\Models\User;
use App\Services\Mfa\MfaService;
use App\Services\Mfa\TotpService;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Volt\Component;

new #[Layout('layouts.guest')] class extends Component
{
    #[Validate('required|string')]
    public string $code = '';

    public function mount(): void
    {
        if (app(MfaService::class)->pendingChallengeUser() === null) {
            $this->redirectRoute('login', navigate: true);
        }
    }

    public function verify(): void
    {
        $this->validate();

        $mfa = app(MfaService::class);
        $user = $mfa->pendingChallengeUser();

        if ($user === null) {
            $this->redirectRoute('login', navigate: true);

            return;
        }

        $key = 'mfa:'.Str::lower($user->email).'|'.request()->ip();

        if (RateLimiter::tooManyAttempts($key, 5)) {
            $mfa->clearChallenge();

            throw ValidationException::withMessages([
                'code' => 'Too many attempts. Please log in again.',
            ]);
        }

        $valid = TotpService::verify($user->mfa_secret ?? '', $this->code);

        if (! $valid) {
            $valid = $mfa->consumeRecoveryCode($user, $this->code);
        }

        if (! $valid) {
            RateLimiter::hit($key, 300);

            throw ValidationException::withMessages([
                'code' => 'The provided code is invalid.',
            ]);
        }

        RateLimiter::clear($key);

        $remember = (bool) session('mfa.pending_remember', false);
        $mfa->clearChallenge();

        auth()->login($user, $remember);
        Session::regenerate();

        $this->redirectIntended(default: route('dashboard', absolute: false), navigate: true);
    }
}; ?>

<div class="w-full sm:max-w-md mt-6 px-6 py-4 bg-white shadow-md overflow-hidden sm:rounded-lg">
    <h2 class="text-lg font-semibold text-gray-900 mb-1">Two-factor authentication</h2>
    <p class="text-sm text-gray-600 mb-4">
        Enter the 6-digit code from your authenticator app, or one of your recovery codes.
    </p>

    <form wire:submit="verify">
        <div>
            <x-input-label for="code" :value="__('Authentication code')" />
            <x-text-input wire:model="code" id="code" class="block mt-1 w-full" type="text" inputmode="numeric" autocomplete="one-time-code" required autofocus />
            <x-input-error :messages="$errors->get('code')" class="mt-2" />
        </div>

        <div class="flex items-center justify-end mt-4">
            <a class="underline text-sm text-gray-600 hover:text-gray-900" href="{{ route('login') }}" wire:navigate>
                {{ __('Back to login') }}
            </a>

            <x-primary-button class="ms-3">
                {{ __('Verify') }}
            </x-primary-button>
        </div>
    </form>
</div>
