<?php

declare(strict_types=1);

namespace App\Services\Mfa;

/**
 * RFC 6238 TOTP (time-based one-time password) over HMAC-SHA1 — implemented
 * locally so the only new dependency is the QR renderer. Secrets are base32
 * (RFC 4648, no padding), 20 bytes like Google Authenticator defaults.
 */
class TotpService
{
    public const PERIOD = 30;

    public const DIGITS = 6;

    public static function generateSecret(int $bytes = 20): string
    {
        return self::base32Encode(random_bytes($bytes));
    }

    /**
     * otpauth:// URL for QR encoding.
     */
    public static function otpauthUrl(string $email, string $secret, string $issuer = 'Employee SaaS'): string
    {
        $label = rawurlencode($issuer.':'.$email);
        $issuer = rawurlencode($issuer);

        return "otpauth://totp/{$label}?secret={$secret}&issuer={$issuer}&period=".self::PERIOD.'&digits='.self::DIGITS.'&algorithm=SHA1';
    }

    /**
     * Current valid code for a secret (for tests / display).
     */
    public static function codeAt(string $secret, ?int $timestamp = null): string
    {
        $timestamp ??= time();
        $counter = intdiv($timestamp, self::PERIOD);

        return self::hotp(self::base32Decode($secret), $counter);
    }

    /**
     * Verify a user-submitted code, allowing ±1 period of clock drift.
     */
    public static function verify(string $secret, string $code, ?int $timestamp = null): bool
    {
        $code = trim($code);

        if (preg_match('/^\d{'.self::DIGITS.'}$/', $code) !== 1) {
            return false;
        }

        $timestamp ??= time();
        $counter = intdiv($timestamp, self::PERIOD);

        foreach ([-1, 0, 1] as $offset) {
            if (hash_equals(self::hotp(self::base32Decode($secret), $counter + $offset), $code)) {
                return true;
            }
        }

        return false;
    }

    /**
     * SHA-1 HOTP core (RFC 4226) reused by TOTP.
     */
    protected static function hotp(string $key, int $counter): string
    {
        $binaryCounter = pack('N2', ($counter >> 32) & 0xFFFFFFFF, $counter & 0xFFFFFFFF);
        $hash = hash_hmac('sha1', $binaryCounter, $key, true);

        $offset = ord($hash[19]) & 0x0F;
        $value = ((ord($hash[$offset]) & 0x7F) << 24)
            | ((ord($hash[$offset + 1]) & 0xFF) << 16)
            | ((ord($hash[$offset + 2]) & 0xFF) << 8)
            | (ord($hash[$offset + 3]) & 0xFF);

        return str_pad((string) ($value % (10 ** self::DIGITS)), self::DIGITS, '0', STR_PAD_LEFT);
    }

    public static function base32Encode(string $data): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $binary = '';
        $bits = 0;
        $value = 0;
        $output = '';

        for ($i = 0, $length = strlen($data); $i < $length; $i++) {
            $value = ($value << 8) | ord($data[$i]);
            $bits += 8;

            while ($bits >= 5) {
                $bits -= 5;
                $output .= $alphabet[($value >> $bits) & 0x1F];
            }
        }

        if ($bits > 0) {
            $output .= $alphabet[($value << (5 - $bits)) & 0x1F];
        }

        return $output;
    }

    public static function base32Decode(string $input): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $input = strtoupper(rtrim(trim($input), '='));
        $binary = '';
        $bits = 0;
        $value = 0;

        for ($i = 0, $length = strlen($input); $i < $length; $i++) {
            $index = strpos($alphabet, $input[$i]);

            if ($index === false) {
                throw new \InvalidArgumentException('Invalid base32 character.');
            }

            $value = ($value << 5) | $index;
            $bits += 5;

            if ($bits >= 8) {
                $bits -= 8;
                $binary .= chr(($value >> $bits) & 0xFF);
            }
        }

        return $binary;
    }
}
