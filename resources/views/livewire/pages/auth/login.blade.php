<?php

use App\Livewire\Forms\LoginForm;
use App\Models\Organization;
use App\Services\Mfa\MfaService;
use Illuminate\Support\Facades\Session;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.guest')] class extends Component
{
    public LoginForm $form;

    /** @var \Illuminate\Support\Collection<int, \App\Models\Organization> */
    public $ssoOrganizations;

    public function mount(): void
    {
        // Phase 8 — OIDC SSO: list orgs with SSO enabled so guests can pick
        // their identity provider from the login page. The enabled flag is
        // filtered in PHP to avoid database-specific JSON boolean casting.
        $this->ssoOrganizations = Organization::query()
            ->where('status', 'active')
            ->orderBy('name')
            ->limit(50)
            ->get(['id', 'name', 'slug', 'settings'])
            ->filter(fn (Organization $organization) => (bool) ($organization->settings['sso_enabled'] ?? false))
            ->take(10)
            ->values();
    }

    /**
     * Handle an incoming authentication request.
     */
    public function login(): void
    {
        $this->validate();

        $this->form->authenticate();

        Session::regenerate();

        // MFA enabled: password was accepted but login is not complete until
        // the TOTP challenge is satisfied.
        if (session()->has(MfaService::CHALLENGE_SESSION_KEY)) {
            $this->redirectRoute('mfa.challenge', navigate: true);

            return;
        }

        $this->redirectIntended(default: route('dashboard', absolute: false), navigate: true);
    }
}; ?>

<div>
    <!-- Session Status -->
    <x-auth-session-status class="mb-4" :status="session('status')" />

    <form wire:submit="login">
        <!-- Email Address -->
        <div>
            <x-input-label for="email" :value="__('Email')" />
            <x-text-input wire:model="form.email" id="email" class="block mt-1 w-full" type="email" name="email" required autofocus autocomplete="username" />
            <x-input-error :messages="$errors->get('form.email')" class="mt-2" />
        </div>

        <!-- Password -->
        <div class="mt-4">
            <x-input-label for="password" :value="__('Password')" />

            <x-text-input wire:model="form.password" id="password" class="block mt-1 w-full"
                            type="password"
                            name="password"
                            required autocomplete="current-password" />

            <x-input-error :messages="$errors->get('form.password')" class="mt-2" />
        </div>

        <!-- Remember Me -->
        <div class="block mt-4">
            <label for="remember" class="inline-flex items-center">
                <input wire:model="form.remember" id="remember" type="checkbox" class="rounded border-gray-300 text-indigo-600 shadow-sm focus:ring-indigo-500" name="remember">
                <span class="ms-2 text-sm text-gray-600">{{ __('Remember me') }}</span>
            </label>
        </div>

        <div class="flex items-center justify-end mt-4">
            @if (Route::has('password.request'))
                <a class="underline text-sm text-gray-600 hover:text-gray-900 rounded-md focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500" href="{{ route('password.request') }}" wire:navigate>
                    {{ __('Forgot your password?') }}
                </a>
            @endif

            <x-primary-button class="ms-3">
                {{ __('Log in') }}
            </x-primary-button>
        </div>
    </form>

    @if ($ssoOrganizations->isNotEmpty())
        <div class="mt-6">
            <div class="relative">
                <div class="absolute inset-0 flex items-center" aria-hidden="true">
                    <div class="w-full border-t border-gray-300"></div>
                </div>
                <div class="relative flex justify-center text-sm">
                    <span class="bg-white px-2 text-gray-500">{{ __('or sign in with SSO') }}</span>
                </div>
            </div>

            <div class="mt-4 space-y-2">
                @foreach ($ssoOrganizations as $organization)
                    <a href="{{ route('sso.login', $organization) }}"
                       class="w-full inline-flex justify-center rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50">
                        {{ $organization->settings['sso_name'] ?? ($organization->name.' SSO') }}
                    </a>
                @endforeach
            </div>
        </div>
    @endif
</div>
