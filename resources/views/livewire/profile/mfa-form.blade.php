<?php

use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Mfa\MfaService;
use App\Services\Mfa\TotpService;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
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

    public function startSetup(): void
    {
        /** @var User $user */
        $user = Auth::user();

        if ($user->mfa_enabled) {
            return;
        }

        $this->secret = app(MfaService::class)->beginSetup($user);
        $this->setupStarted = true;
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
        $this->secret = '';
        $this->code = '';

        app(AuditLogger::class)->log('mfa.enabled', $user);
    }

    public function regenerateRecoveryCodes(): void
    {
        $this->validate(['password' => ['required', 'string'], 'code' => ['required', 'string']]);

        /** @var User $user */
        $user = Auth::user();

        if (! Hash::check($this->password, $user->password) || ! TotpService::verify($user->mfa_secret ?? '', $this->code)) {
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

        if (! Hash::check($this->password, $user->password) || ! TotpService::verify($user->mfa_secret ?? '', $this->code)) {
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
}; ?>

<div>
    <section>
        <header>
            <h2 class="text-lg font-medium text-gray-900">
                {{ __('Two-factor authentication') }}
            </h2>

            <p class="mt-1 text-sm text-gray-600">
                {{ __('Add an extra layer of security by requiring a rotating code from an authenticator app at login.') }}
            </p>
        </header>

        @if ($recoveryCodes !== null)
            <div class="mt-4 rounded-md bg-amber-50 border border-amber-200 p-4">
                <p class="text-sm font-medium text-amber-800 mb-2">Store these recovery codes somewhere safe — each works once if you lose your authenticator.</p>
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
                    <x-secondary-button wire:click="startSetup">
                        {{ __('Enable 2FA') }}
                    </x-secondary-button>
                @else
                    <p class="text-sm text-gray-700">Scan this QR code with your authenticator app (or enter the secret manually), then confirm with the 6-digit code it shows.</p>

                    <div class="inline-block p-3 bg-white border rounded-lg" wire:poll.5s>
                        {!! $qrSvg() !!}
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
                        <x-secondary-button wire:click="$set('setupStarted', false)">
                            {{ __('Cancel') }}
                        </x-secondary-button>
                    </div>
                @endunless
            </div>
        @else
            <p class="mt-4 text-sm text-gray-700">Two-factor authentication is <span class="font-semibold text-green-700">enabled</span>.</p>

            <div class="mt-4 space-y-4 max-w-sm">
                <div>
                    <x-input-label for="mfa-regen-password" :value="__('Password')" />
                    <x-text-input wire:model="password" id="mfa-regen-password" class="block mt-1 w-full" type="password" autocomplete="current-password" />
                </div>
                <div>
                    <x-input-label for="mfa-regen-code" :value="__('Authentication code')" />
                    <x-text-input wire:model="code" id="mfa-regen-code" class="block mt-1 w-full" type="text" inputmode="numeric" />
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
