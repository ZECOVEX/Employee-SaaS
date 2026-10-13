<?php

declare(strict_types=1);

namespace App\Services\Mfa;

use App\Models\User;
use App\Notifications\MfaEmailCode;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * MFA setup, verification and recovery-code lifecycle. Post-Phase 21 — the
 * user picks the method at enrolment: 'totp' (authenticator app, secret on
 * the user in an encrypted cast) or 'email' (6-digit code mailed on demand).
 * Secrets/pending setup live in the session until confirm so a refresh
 * mid-enrolment does not leak state to the database.
 */
class MfaService
{
    public const PENDING_SESSION_KEY = 'mfa.pending_secret';

    public const CHALLENGE_SESSION_KEY = 'mfa.pending_user_id';

    public const METHOD_TOTP = 'totp';

    public const METHOD_EMAIL = 'email';

    public const PENDING_METHOD_SESSION_KEY = 'mfa.pending_method';

    public const SETUP_CODE_SESSION_KEY = 'mfa.setup_code';

    public const LOGIN_CODE_SESSION_KEY = 'mfa.login_code';

    public const CODE_TTL_MINUTES = 10;

    public const RECOVERY_CODE_COUNT = 8;

    /**
     * Begin enrolment for the chosen method. TOTP stages a fresh secret;
     * email stages the method and mails a one-time confirm code.
     * Returns the TOTP secret, or null for the email method.
     */
    public function beginSetup(User $user, string $method = self::METHOD_TOTP): ?string
    {
        session([self::PENDING_METHOD_SESSION_KEY => $method]);

        if ($method === self::METHOD_EMAIL) {
            $this->sendStagedCode($user, self::SETUP_CODE_SESSION_KEY, 'setup');

            return null;
        }

        $secret = TotpService::generateSecret();

        session([self::PENDING_SESSION_KEY => $secret]);

        return $secret;
    }

    public function pendingSecret(): ?string
    {
        return session(self::PENDING_SESSION_KEY);
    }

    public function pendingSetupMethod(): ?string
    {
        return session(self::PENDING_METHOD_SESSION_KEY);
    }

    /**
     * Verify the code against the staged setup (TOTP secret or emailed code),
     * then persist + issue recovery codes. The method comes from the session.
     *
     * @return array<int, string> plaintext recovery codes (shown once)
     */
    public function confirmSetup(User $user, string $code): array
    {
        $method = $this->pendingSetupMethod();

        if ($method === self::METHOD_EMAIL) {
            $valid = $this->verifyStagedCode(self::SETUP_CODE_SESSION_KEY, $code);
        } else {
            $secret = $this->pendingSecret();
            $valid = $secret !== null && TotpService::verify($secret, $code);
        }

        if (! $valid) {
            return [];
        }

        $recoveryCodes = self::makeRecoveryCodes();

        $user->forceFill([
            'mfa_secret' => $method === self::METHOD_EMAIL ? null : $this->pendingSecret(),
            'mfa_enabled' => true,
            'mfa_method' => $method === self::METHOD_EMAIL ? self::METHOD_EMAIL : self::METHOD_TOTP,
            'mfa_recovery_codes' => array_map(
                fn (string $plain) => hash('sha256', $plain),
                $recoveryCodes,
            ),
        ])->save();

        session()->forget([
            self::PENDING_SESSION_KEY,
            self::PENDING_METHOD_SESSION_KEY,
            self::SETUP_CODE_SESSION_KEY,
        ]);

        return $recoveryCodes;
    }

    /**
     * Resend the setup confirm code (email method); throws when rate limited.
     */
    public function resendSetupCode(User $user): void
    {
        $this->sendStagedCode($user, self::SETUP_CODE_SESSION_KEY, 'setup', strict: true);
    }

    /**
     * Consume a recovery code (single use). Returns true when it matched.
     */
    public function consumeRecoveryCode(User $user, string $code): bool
    {
        $code = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $code) ?? '');
        $hashed = $user->mfa_recovery_codes ?? [];
        $match = null;

        foreach ($hashed as $index => $hash) {
            if (hash_equals($hash, hash('sha256', $code))) {
                $match = $index;
                break;
            }
        }

        if ($match === null) {
            return false;
        }

        unset($hashed[$match]);
        $user->forceFill(['mfa_recovery_codes' => array_values($hashed)])->save();

        return true;
    }

    /**
     * Verify a challenge code for the user's active method (TOTP secret or
     * the staged emailed login code). Recovery codes are handled by callers
     * via consumeRecoveryCode().
     */
    public function verifyChallengeCode(User $user, string $code): bool
    {
        if ($user->mfa_method === self::METHOD_EMAIL) {
            return $this->verifyStagedCode(self::LOGIN_CODE_SESSION_KEY, $code);
        }

        return TotpService::verify($user->mfa_secret ?? '', $code);
    }

    /**
     * Send (or resend) the login challenge code for the email method.
     * Silently skipped when rate limited unless $strict (setup flows).
     */
    public function sendLoginCode(User $user, bool $strict = false): void
    {
        $this->sendStagedCode($user, self::LOGIN_CODE_SESSION_KEY, 'sign-in', $strict);
    }

    /**
     * Disable MFA and wipe secret + method + recovery codes.
     */
    public function disable(User $user): void
    {
        $user->forceFill([
            'mfa_secret' => null,
            'mfa_enabled' => false,
            'mfa_method' => null,
            'mfa_recovery_codes' => null,
        ])->save();

        session()->forget([
            self::PENDING_SESSION_KEY,
            self::PENDING_METHOD_SESSION_KEY,
            self::SETUP_CODE_SESSION_KEY,
            self::LOGIN_CODE_SESSION_KEY,
        ]);
    }

    /**
     * Rotate recovery codes (password + valid TOTP or recovery code required
     * upstream — recovery-code access means the authenticator app was lost).
     *
     * @return array<int, string>
     */
    public function regenerateRecoveryCodes(User $user): array
    {
        $recoveryCodes = self::makeRecoveryCodes();

        $user->forceFill([
            'mfa_recovery_codes' => array_map(
                fn (string $plain) => hash('sha256', $plain),
                $recoveryCodes,
            ),
        ])->save();

        return $recoveryCodes;
    }

    /**
     * Stage the post-password challenge: the user proved the password but
     * still must satisfy MFA. We log them out and remember who they are;
     * email-method users also get a login code mailed here.
     */
    public function stageChallenge(User $user, bool $remember = false): void
    {
        session([
            self::CHALLENGE_SESSION_KEY => $user->id,
            'mfa.pending_remember' => $remember,
        ]);

        if ($user->mfa_method === self::METHOD_EMAIL) {
            $this->sendLoginCode($user);
        }
    }

    public function pendingChallengeUser(): ?User
    {
        $id = session(self::CHALLENGE_SESSION_KEY);

        return $id ? User::find($id) : null;
    }

    public function clearChallenge(): void
    {
        session()->forget([
            self::CHALLENGE_SESSION_KEY,
            'mfa.pending_remember',
            self::LOGIN_CODE_SESSION_KEY,
        ]);
    }

    /**
     * Generate a 6-digit code, hash it into the session with a TTL, and mail
     * it. Rate limited per user (5 codes / 10 minutes).
     */
    protected function sendStagedCode(User $user, string $sessionKey, string $context, bool $strict = false): void
    {
        $limiterKey = 'mfa:code:'.$user->id;

        if (RateLimiter::tooManyAttempts($limiterKey, 5)) {
            if ($strict) {
                throw ValidationException::withMessages([
                    'code' => 'Too many codes requested. Please wait a few minutes.',
                ]);
            }

            return;
        }

        RateLimiter::hit($limiterKey, 600);

        $code = (string) random_int(100000, 999999);

        session([$sessionKey => [
            'hash' => hash('sha256', $code),
            'expires_at' => now()->addMinutes(self::CODE_TTL_MINUTES)->getTimestamp(),
        ]]);

        $user->notify(new MfaEmailCode($code, $context));
    }

    protected function verifyStagedCode(string $sessionKey, string $code): bool
    {
        $staged = session($sessionKey);

        if (
            ! is_array($staged)
            || ! isset($staged['hash'], $staged['expires_at'])
            || now()->getTimestamp() > (int) $staged['expires_at']
            || ! preg_match('/^\d{6}$/', $code)
            || ! hash_equals((string) $staged['hash'], hash('sha256', $code))
        ) {
            return false;
        }

        session()->forget($sessionKey);

        return true;
    }

    /**
     * @return array<int, string>
     */
    protected static function makeRecoveryCodes(): array
    {
        $codes = [];

        while (count($codes) < self::RECOVERY_CODE_COUNT) {
            $codes[] = strtoupper(Str::lower(Str::random(10)));
        }

        return $codes;
    }
}
