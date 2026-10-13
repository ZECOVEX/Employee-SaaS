<?php

use App\Models\User;
use App\Models\UserLoginHistory;
use App\Services\Geo\LoginHistoryService;
use App\Services\Mfa\MfaService;
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

    public bool $emailMethod = false;

    public function mount(): void
    {
        $user = app(MfaService::class)->pendingChallengeUser();

        // Stale challenge: no staged user, or MFA was disabled after the
        // challenge was staged — bounce to login instead of asking for a code.
        if ($user === null || ! $user->mfa_enabled) {
            app(MfaService::class)->clearChallenge();
            $this->redirectRoute('login', navigate: true);

            return;
        }

        // Post-Phase 21 — email-method users get a 6-digit code mailed here
        // (the service rate-limits silently on the login path).
        $this->emailMethod = $user->mfa_method === MfaService::METHOD_EMAIL;

        if ($this->emailMethod) {
            app(MfaService::class)->sendLoginCode($user);
        }
    }

    public function resendCode(): void
    {
        $user = app(MfaService::class)->pendingChallengeUser();

        if ($user !== null && $this->emailMethod) {
            app(MfaService::class)->sendLoginCode($user);
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

        // §43 — a deactivated account cannot complete the MFA challenge.
        if ($user->isDeactivated()) {
            $mfa->clearChallenge();
            $this->redirectRoute('login', navigate: true);

            return;
        }

        // MFA was disabled after this challenge was staged — no code needed.
        if (! $user->mfa_enabled) {
            $mfa->clearChallenge();
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

        $valid = $mfa->verifyChallengeCode($user, $this->code);

        if (! $valid) {
            $valid = $mfa->consumeRecoveryCode($user, $this->code);
        }

        if (! $valid) {
            RateLimiter::hit($key, 300);
            app(LoginHistoryService::class)->recordFromRequest($user, UserLoginHistory::STATUS_MFA_FAILED);

            throw ValidationException::withMessages([
                'code' => 'The provided code is invalid.',
            ]);
        }

        RateLimiter::clear($key);

        $remember = (bool) session('mfa.pending_remember', false);
        $mfa->clearChallenge();

        auth()->login($user, $remember);
        Session::regenerate();
        app(LoginHistoryService::class)->recordFromRequest($user, UserLoginHistory::STATUS_SUCCESS);

        $this->redirectIntended(default: route('dashboard', absolute: false), navigate: true);
    }
}; ?>

<div class="w-full sm:max-w-md mt-6 px-6 py-4 bg-white shadow-md overflow-hidden sm:rounded-lg">
    <h2 class="text-lg font-semibold text-gray-900 mb-1">Two-factor authentication</h2>

    @if ($emailMethod)
        <p class="text-sm text-gray-600 mb-4">
            We emailed a 6-digit code to your account address. Enter it below, or use one of your recovery codes.
        </p>
    @else
        <p class="text-sm text-gray-600 mb-4">
            Enter the 6-digit code from your authenticator app, or one of your recovery codes.
        </p>
    @endif

    <form wire:submit="verify">
        <div>
            <x-input-label for="code" :value="__('Authentication code')" />
            <x-text-input wire:model="code" id="code" class="block mt-1 w-full" type="text" inputmode="numeric" autocomplete="one-time-code" required autofocus />
            <x-input-error :messages="$errors->get('code')" class="mt-2" />
        </div>

        <div class="flex items-center justify-between mt-4">
            <div class="flex items-center gap-3">
                <a class="underline text-sm text-gray-600 hover:text-gray-900" href="{{ route('login') }}" wire:navigate>
                    {{ __('Back to login') }}
                </a>

                @if ($emailMethod)
                    <button type="button" class="underline text-sm text-gray-600 hover:text-gray-900" wire:click="resendCode">
                        {{ __('Resend code') }}
                    </button>
                @endif
            </div>

            <x-primary-button>
                {{ __('Verify') }}
            </x-primary-button>
        </div>
    </form>
</div>
