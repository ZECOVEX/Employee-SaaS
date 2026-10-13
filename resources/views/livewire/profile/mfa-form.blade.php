<?php

use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Mfa\MfaService;
use App\Services\Mfa\TotpService;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Validate;
use Livewire\Volt\Component;

new class extends Component
{
    public bool $enabled = false;

    public bool $setupStarted = false;

    public string $pendingMethod = MfaService::METHOD_TOTP;

    public string $secret = '';

    /** @var array<int, string>|null shown once after confirm/regenerate */
    public ?array $recoveryCodes = null;

    #[Validate('required|string')]
    public string $code = '';

    #[Validate('nullable|string|min:8')]
    public string $password = '';

    public function mount(): void
    {
        /** @var User $user */
        $user = Auth::user();
        $this->enabled = (bool) $user->mfa_enabled;
    }

    public function startSetup(string $method = MfaService::METHOD_TOTP): void
    {
        if (! in_array($method, [MfaService::METHOD_TOTP, MfaService::METHOD_EMAIL], true)) {
            return;
        }

        /** @var User $user */
        $user = Auth::user();

        if ($user->mfa_enabled) {
            return;
        }

        $this->pendingMethod = $method;
        $this->secret = app(MfaService::class)->beginSetup($user, $method) ?? '';
        $this->setupStarted = true;
        $this->code = '';
    }

    public function resendSetupCode(): void
    {
        /** @var User $user */
        $user = Auth::user();

        if ($user->mfa_enabled || ! $this->setupStarted || $this->pendingMethod !== MfaService::METHOD_EMAIL) {
            return;
        }

        app(MfaService::class)->resendSetupCode($user);
    }

    public function cancelSetup(): void
    {
        $this->setupStarted = false;
        $this->secret = '';
        $this->code = '';
    }

    public function qrSvg(): string
    {
        if ($this->secret === '') {
            return '';
        }

        /** @var User $user */
        $user = Auth::user();

        $url = TotpService::otpauthUrl($user->email, $this->secret, config('app.name', 'Employee SaaS'));

        $renderer = new ImageRenderer(new RendererStyle(190), new SvgImageBackEnd());

        return (new Writer($renderer))->writeString($url);
    }

    public function confirm(): void
    {
        $this->validate();

        /** @var User $user */
        $user = Auth::user();

        $codes = app(MfaService::class)->confirmSetup($user, $this->code);

        if ($codes === []) {
            throw ValidationException::withMessages([
                'code' => 'The provided code is invalid.',
            ]);
        }

        $this->recoveryCodes = $codes;
        $this->enabled = true;
        $this->setupStarted = false;
        $this->pendingMethod = MfaService::METHOD_TOTP;
        $this->secret = '';
        $this->code = '';

        app(AuditLogger::class)->log('mfa.enabled', $user);
    }

    public function regenerateRecoveryCodes(): void
    {
        $this->validate(['password' => ['required', 'string'], 'code' => ['required', 'string']]);

        /** @var User $user */
        $user = Auth::user();

        if (! Hash::check($this->password, $user->password) || ! $this->codePasses($user, $this->code)) {
            throw ValidationException::withMessages([
                'password' => 'Password or code is incorrect.',
            ]);
        }

        $this->recoveryCodes = app(MfaService::class)->regenerateRecoveryCodes($user);
        $this->password = '';
        $this->code = '';

        app(AuditLogger::class)->log('mfa.recovery_regenerated', $user);
    }

    public function disable(): void
    {
        $this->validate(['password' => ['required', 'string'], 'code' => ['required', 'string']]);

        /** @var User $user */
        $user = Auth::user();

        if (! Hash::check($this->password, $user->password) || ! $this->codePasses($user, $this->code)) {
            throw ValidationException::withMessages([
                'password' => 'Password or code is incorrect.',
            ]);
        }

        app(MfaService::class)->disable($user);

        $this->enabled = false;
        $this->recoveryCodes = null;
        $this->password = '';
        $this->code = '';

        app(AuditLogger::class)->log('mfa.disabled', $user);
    }

    /** TOTP code or a one-time recovery code (authenticator app was lost). */
    protected function codePasses(User $user, string $code): bool
    {
        if (TotpService::verify($user->mfa_secret ?? '', $code)) {
            return true;
        }

        return app(MfaService::class)->consumeRecoveryCode($user, $code);
    }
}; ?>

<div>
    <section>
        <header>
            <h2 class="text-lg font-medium text-gray-900">
                {{ __('Two-factor authentication') }}
            </h2>

            <p class="mt-1 text-sm text-gray-600">
                {{ __('Protect your account with a second step at login — an authenticator app or a code sent to your email.') }}
            </p>
        </header>

        @if ($recoveryCodes !== null)
            <div class="mt-4 rounded-md bg-amber-50 border border-amber-200 p-4">
                <p class="text-sm font-medium text-amber-800 mb-2">Store these recovery codes somewhere safe — each works once if you lose access to your second factor.</p>
                <ul class="grid grid-cols-2 gap-1 font-mono text-sm text-amber-900">
                    @foreach ($recoveryCodes as $recoveryCode)
                        <li>{{ $recoveryCode }}</li>
                    @endforeach
                </ul>
                <x-secondary-button class="mt-3" wire:click="$set('recoveryCodes', null)">
                    {{ __('I saved them') }}
                </x-secondary-button>
            </div>
        @endif

        @unless ($enabled)
            <div class="mt-4 space-y-4">
                @unless ($setupStarted)
                    <p class="text-sm text-gray-700">Choose how you want to confirm it's you at login:</p>

                    <div class="flex flex-col sm:flex-row gap-3">
                        <x-secondary-button wire:click="startSetup('{{ \App\Services\Mfa\MfaService::METHOD_TOTP }}')">
                            {{ __('Authenticator app') }}
                        </x-secondary-button>
                        <x-secondary-button wire:click="startSetup('{{ \App\Services\Mfa\MfaService::METHOD_EMAIL }}')">
                            {{ __('Email code') }}
                        </x-secondary-button>
                    </div>
                @elseif ($pendingMethod === \App\Services\Mfa\MfaService::METHOD_EMAIL)
                    <p class="text-sm text-gray-700">
                        We emailed a 6-digit code to <span class="font-semibold">{{ Auth::user()->email }}</span>. Enter it below to confirm and enable email codes for login.
                    </p>

                    <div>
                        <x-input-label for="mfa-code" :value="__('Email code')" />
                        <x-text-input wire:model="code" id="mfa-code" class="block mt-1 w-full" type="text" inputmode="numeric" autocomplete="one-time-code" />
                        <x-input-error :messages="$errors->get('code')" class="mt-2" />
                    </div>

                    <div class="flex items-center gap-3">
                        <x-primary-button wire:click="confirm">
                            {{ __('Confirm and enable') }}
                        </x-primary-button>
                        <x-secondary-button wire:click="resendSetupCode">
                            {{ __('Resend code') }}
                        </x-secondary-button>
                        <x-secondary-button wire:click="cancelSetup">
                            {{ __('Cancel') }}
                        </x-secondary-button>
                    </div>
                @else
                    <p class="text-sm text-gray-700">Scan this QR code with your authenticator app (or enter the secret manually), then confirm with the 6-digit code it shows.</p>

                    <div class="inline-block p-3 bg-white border rounded-lg" wire:poll.5s>
                        {!! $this->qrSvg() !!}
                    </div>

                    <p class="font-mono text-sm text-gray-700 select-all">{{ $secret }}</p>

                    <div>
                        <x-input-label for="mfa-code" :value="__('Authentication code')" />
                        <x-text-input wire:model="code" id="mfa-code" class="block mt-1 w-full" type="text" inputmode="numeric" autocomplete="one-time-code" />
                        <x-input-error :messages="$errors->get('code')" class="mt-2" />
                    </div>

                    <div class="flex items-center gap-3">
                        <x-primary-button wire:click="confirm">
                            {{ __('Confirm and enable') }}
                        </x-primary-button>
                        <x-secondary-button wire:click="cancelSetup">
                            {{ __('Cancel') }}
                        </x-secondary-button>
                    </div>
                @endunless
            </div>
        @else
            @php
                $methodLabel = Auth::user()->mfa_method === \App\Services\Mfa\MfaService::METHOD_EMAIL ? 'email codes' : 'an authenticator app';
            @endphp
            <p class="mt-4 text-sm text-gray-700">
                Two-factor authentication is <span class="font-semibold text-green-700">enabled</span> via {{ $methodLabel }}.
            </p>

            <div class="mt-4 space-y-4 max-w-sm">
                <div>
                    <x-input-label for="mfa-regen-password" :value="__('Password')" />
                    <x-text-input wire:model="password" id="mfa-regen-password" class="block mt-1 w-full" type="password" autocomplete="current-password" />
                </div>
                <div>
                    <x-input-label for="mfa-regen-code" :value="__('Authentication or recovery code')" />
                    <x-text-input wire:model="code" id="mfa-regen-code" class="block mt-1 w-full" type="text" inputmode="numeric" />
                    <p class="mt-1 text-xs text-gray-500">Lost your authenticator? Enter one of your recovery codes instead.</p>
                    <x-input-error :messages="$errors->get('password')" class="mt-2" />
                </div>

                <div class="flex items-center gap-3">
                    <x-secondary-button wire:click="regenerateRecoveryCodes">
                        {{ __('Regenerate recovery codes') }}
                    </x-secondary-button>
                    <x-danger-button wire:click="disable">
                        {{ __('Disable 2FA') }}
                    </x-danger-button>
                </div>
            </div>
        @endunless
    </section>
</div>
