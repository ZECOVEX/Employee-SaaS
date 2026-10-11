<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use App\Notifications\PasswordChangeRequired;
use Illuminate\Support\Str;

/**
 * Admin/manager-issued password resets (§ password recovery). Issues an
 * 8-character hard password (MD5 of random entropy, first 8 hex chars),
 * flags the account to change it at next login, rotates the remember token,
 * notifies the user, and audits the action without ever storing the value.
 */
class PasswordResetService
{
    /**
     * Reset the user's password to a fresh temporary one.
     * Returns the plaintext password exactly once, for the actor to relay.
     */
    public function issue(User $user): string
    {
        $password = substr(md5(bin2hex(random_bytes(16))), 0, 8);

        $user->forceFill([
            'password' => $password,
            'must_change_password' => true,
            'remember_token' => Str::random(60),
        ])->save();

        $user->notify(new PasswordChangeRequired);

        app(AuditLogger::class)->log('user.password_reset', $user, null, [
            'password' => '(redacted)',
            'must_change_password' => true,
        ]);

        return $password;
    }
}
