<?php

declare(strict_types=1);

namespace App\Livewire\Forms;

use App\Models\User;
use App\Models\UserLoginHistory;
use App\Services\Geo\LoginHistoryService;
use App\Services\Mfa\MfaService;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Validate;
use Livewire\Form;

class LoginForm extends Form
{
    #[Validate('required|string|email')]
    public string $email = '';

    #[Validate('required|string')]
    public string $password = '';

    #[Validate('boolean')]
    public bool $remember = false;

    /**
     * Attempt to authenticate the request's credentials.
     *
     * When the account has TOTP enabled the password is accepted but the
     * session is dropped and the MFA challenge is staged instead — login is
     * only completed on the challenge page.
     *
     * @throws ValidationException
     */
    public function authenticate(): void
    {
        $this->ensureIsNotRateLimited();

        if (! Auth::attempt($this->only(['email', 'password']), $this->remember)) {
            RateLimiter::hit($this->throttleKey());

            // §71 — record the failed attempt (with GeoIP resolution) even
            // when the email is unknown, so suspicious patterns surface.
            $existing = User::where('email', $this->email)->first();
            app(LoginHistoryService::class)->recordFromRequest(
                $existing,
                UserLoginHistory::STATUS_FAILED,
            );

            throw ValidationException::withMessages([
                'form.email' => trans('auth.failed'),
            ]);
        }

        /** @var User $user */
        $user = Auth::user();

        // §43 — admin-controlled deactivation blocks every login path.
        if ($user->isDeactivated()) {
            Auth::logout();

            app(LoginHistoryService::class)->recordFromRequest(
                $user,
                UserLoginHistory::STATUS_FAILED,
            );

            throw ValidationException::withMessages([
                'form.email' => 'This account has been deactivated. Contact your administrator.',
            ]);
        }

        if ($user->mfa_enabled) {
            Auth::logout();
            app(MfaService::class)->stageChallenge($user, $this->remember);
            RateLimiter::clear($this->throttleKey());

            return;
        }

        app(LoginHistoryService::class)->recordFromRequest($user, UserLoginHistory::STATUS_SUCCESS);
        RateLimiter::clear($this->throttleKey());
    }

    /**
     * Ensure the authentication request is not rate limited.
     */
    protected function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), 5)) {
            return;
        }

        event(new Lockout(request()));

        $seconds = RateLimiter::availableIn($this->throttleKey());

        throw ValidationException::withMessages([
            'form.email' => trans('auth.throttle', [
                'seconds' => $seconds,
                'minutes' => ceil($seconds / 60),
            ]),
        ]);
    }

    /**
     * Get the authentication rate limiting throttle key.
     */
    protected function throttleKey(): string
    {
        return Str::transliterate(Str::lower($this->email).'|'.request()->ip());
    }
}
