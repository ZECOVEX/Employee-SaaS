<?php

declare(strict_types=1);

namespace App\Services\Mfa;

use App\Models\User;
use Illuminate\Support\Str;

/**
 * MFA setup, verification and recovery-code lifecycle. Secrets live on the
 * user (encrypted cast); pending setup is staged in the session so a refresh
 * mid-enrolment does not leak the secret to the database.
 */
class MfaService
{
    public const PENDING_SESSION_KEY = 'mfa.pending_secret';

    public const CHALLENGE_SESSION_KEY = 'mfa.pending_user_id';

    public const RECOVERY_CODE_COUNT = 8;

    /**
     * Stage a new secret in the session; nothing is persisted until confirm.
     */
    public function beginSetup(User $user): string
    {
        $secret = TotpService::generateSecret();

        session([self::PENDING_SESSION_KEY => $secret]);

        return $secret;
    }

    public function pendingSecret(): ?string
    {
        return session(self::PENDING_SESSION_KEY);
    }

    /**
     * Verify the code against the staged secret, then persist + issue recovery codes.
     *
     * @return array<int, string> plaintext recovery codes (shown once)
     */
    public function confirmSetup(User $user, string $code): array
    {
        $secret = $this->pendingSecret();

        if ($secret === null || ! TotpService::verify($secret, $code)) {
            return [];
        }

        $recoveryCodes = self::makeRecoveryCodes();

        $user->forceFill([
            'mfa_secret' => $secret,
            'mfa_enabled' => true,
            'mfa_recovery_codes' => array_map(
                fn (string $plain) => hash('sha256', $plain),
                $recoveryCodes,
            ),
        ])->save();

        session()->forget(self::PENDING_SESSION_KEY);

        return $recoveryCodes;
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
     * Disable MFA and wipe secret + recovery codes.
     */
    public function disable(User $user): void
    {
        $user->forceFill([
            'mfa_secret' => null,
            'mfa_enabled' => false,
            'mfa_recovery_codes' => null,
        ])->save();

        session()->forget([self::PENDING_SESSION_KEY, self::CHALLENGE_SESSION_KEY]);
    }

    /**
     * Rotate recovery codes (password + valid TOTP code required upstream).
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
     * still must satisfy MFA. We log them out and remember who they are.
     */
    public function stageChallenge(User $user, bool $remember = false): void
    {
        session([
            self::CHALLENGE_SESSION_KEY => $user->id,
            'mfa.pending_remember' => $remember,
        ]);
    }

    public function pendingChallengeUser(): ?User
    {
        $id = session(self::CHALLENGE_SESSION_KEY);

        return $id ? User::find($id) : null;
    }

    public function clearChallenge(): void
    {
        session()->forget([self::CHALLENGE_SESSION_KEY, 'mfa.pending_remember']);
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
