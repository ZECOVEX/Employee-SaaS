<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'slug', 'timezone', 'status', 'settings'])]
class Organization extends Model
{
    protected function casts(): array
    {
        return [
            'settings' => 'array',
        ];
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function departments(): HasMany
    {
        return $this->hasMany(Department::class);
    }

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }

    public function roles(): HasMany
    {
        return $this->hasMany(Role::class);
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /**
     * NFC double-tap debounce window in seconds (spec §72, default 15).
     */
    public function debounceSeconds(): int
    {
        $value = (int) ($this->settings['debounce_seconds'] ?? 15);

        return max(5, min(300, $value));
    }

    /**
     * Clock display format chosen in the admin settings panel: '24h' (16:00)
     * or '12h' (4:00 PM). Defaults to the international 24-hour format.
     */
    public function timeFormat(): string
    {
        $format = $this->settings['time_format'] ?? '24h';

        return in_array($format, ['24h', '12h'], true) ? $format : '24h';
    }

    /**
     * ISO-style currency code used for salary/payslip amounts (settings form).
     */
    public function currency(): string
    {
        $currency = $this->settings['currency'] ?? 'BDT';

        return is_string($currency) && $currency !== '' ? strtoupper(substr($currency, 0, 8)) : 'BDT';
    }

    /**
     * Configurable salary deduction rules (§28): grace, late method, rounding,
     * absence/half-day treatment, unpaid-leave treatment and the monthly cap.
     *
     * @return array<string, mixed>
     */
    public function salaryRules(): array
    {
        $settings = $this->settings ?? [];
        $enum = function (string $key, array $allowed, string $default) use ($settings): string {
            $value = $settings[$key] ?? $default;

            return is_string($value) && in_array($value, $allowed, true) ? $value : $default;
        };

        return [
            'currency' => $this->currency(),
            'late_deduction' => $enum('late_deduction', ['per_minute', 'none'], 'per_minute'),
            'deduction_grace_minutes' => max(0, min(240, (int) ($settings['deduction_grace_minutes'] ?? 0))),
            'deduction_rounding' => $enum('deduction_rounding', ['exact', 'nearest', 'whole'], 'exact'),
            'absence_deduction' => $enum('absence_deduction', ['full_day', 'half_day', 'none'], 'full_day'),
            'half_day_deduction' => $enum('half_day_deduction', ['full_day', 'half_day', 'none'], 'half_day'),
            'unpaid_leave_deduction' => (bool) ($settings['unpaid_leave_deduction'] ?? true),
            'max_deduction_percent' => max(0, min(100, (int) ($settings['max_deduction_percent'] ?? 100))),
        ];
    }

    /**
     * Format a clock time per the organization's time-format setting.
     *
     * Attendance values are stored as naive wall-clock times in the
     * organization's timezone (exactly what the admin typed or the employee
     * tapped), so they are formatted as-is — no timezone conversion, which
     * would otherwise shift e.g. 10:00 AM to 4:00 PM for Asia/Dhaka (UTC+6).
     * Strings like "09:00:00" are schedule wall times and behave the same.
     */
    public function formatTime(Carbon|\DateTimeInterface|string|null $value, bool $withSeconds = false): ?string
    {
        if ($value === null) {
            return null;
        }

        $dt = is_string($value)
            ? Carbon::parse($value)
            : ($value instanceof Carbon ? $value->copy() : Carbon::instance($value));

        if ($this->timeFormat() === '12h') {
            return $dt->format($withSeconds ? 'g:i:s A' : 'g:i A');
        }

        return $dt->format($withSeconds ? 'H:i:s' : 'H:i');
    }
}
