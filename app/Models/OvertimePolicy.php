<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Effective-dated overtime policy version (§73-G). Like work schedules (§11),
 * edits close the current version and open a new one so history never rewrites.
 */
#[Fillable([
    'organization_id',
    'effective_from',
    'effective_to',
    'enabled',
    'mode',
    'start_threshold_minutes',
    'rounding_minutes',
    'rounding_method',
    'daily_cap_minutes',
    'weekly_cap_minutes',
    'monthly_cap_minutes',
    'max_shift_minutes',
    'approval_mode',
    'pending_expiry_days',
    'pending_expiry_action',
    'weekday_multiplier',
    'weekend_multiplier',
    'holiday_multiplier',
    'night_window_start',
    'night_window_end',
    'night_multiplier',
    'rate_base',
    'offset_late_with_overtime',
    'weekend_all_overtime',
    'compensation_type',
    'require_pre_approval',
    'show_pay_to_employee',
    'show_hours_to_employee',
    'overtime_score_bonus_max',
    'created_by',
])]
class OvertimePolicy extends Model
{
    use BelongsToOrganization;

    public const MODE_AFTER_END = 'AFTER_OFFICE_END';

    public const MODE_ABOVE_EXPECTED = 'ABOVE_EXPECTED_HOURS';

    public const ROUND_UP = 'UP';

    public const ROUND_DOWN = 'DOWN';

    public const ROUND_NEAREST = 'NEAREST';

    public const APPROVE_AUTO = 'AUTO';

    public const APPROVE_MANAGER = 'MANAGER';

    public const APPROVE_MANAGER_HR = 'MANAGER_HR';

    public const EXPIRE_AUTO_APPROVE = 'AUTO_APPROVE';

    public const EXPIRE_AUTO_REJECT = 'AUTO_REJECT';

    public const EXPIRE_ESCALATE = 'ESCALATE';

    public const RATE_GROSS = 'GROSS';

    public const RATE_BASIC = 'BASIC';

    public const RATE_CUSTOM = 'CUSTOM';

    public const COMP_PAID = 'PAID';

    public const COMP_TIME = 'COMP_TIME';

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'offset_late_with_overtime' => 'boolean',
            'weekend_all_overtime' => 'boolean',
            'require_pre_approval' => 'boolean',
            'show_pay_to_employee' => 'boolean',
            'show_hours_to_employee' => 'boolean',
            'start_threshold_minutes' => 'integer',
            'rounding_minutes' => 'integer',
            'daily_cap_minutes' => 'integer',
            'weekly_cap_minutes' => 'integer',
            'monthly_cap_minutes' => 'integer',
            'max_shift_minutes' => 'integer',
            'pending_expiry_days' => 'integer',
            'overtime_score_bonus_max' => 'integer',
            'weekday_multiplier' => 'float',
            'weekend_multiplier' => 'float',
            'holiday_multiplier' => 'float',
            'night_multiplier' => 'float',
            'effective_from' => 'date:Y-m-d',
            'effective_to' => 'date:Y-m-d',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * The policy version in effect for a given date (§73-G effective dating).
     */
    public static function effectiveFor(int $organizationId, string $date): ?self
    {
        return self::withoutGlobalScopes()
            ->where('organization_id', $organizationId)
            ->where(function ($q) use ($date) {
                $q->whereNull('effective_from')->orWhere('effective_from', '<=', $date);
            })
            ->where(function ($q) use ($date) {
                $q->whereNull('effective_to')->orWhere('effective_to', '>=', $date);
            })
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Spec §73-G defaults — used when the organization never opened the policy
     * page, and as the shape of the first saved version.
     *
     * @return array<string, mixed>
     */
    public static function defaults(): array
    {
        return [
            'enabled' => true,
            'mode' => self::MODE_ABOVE_EXPECTED,
            'start_threshold_minutes' => 15,
            'rounding_minutes' => 15,
            'rounding_method' => self::ROUND_NEAREST,
            'daily_cap_minutes' => 120,
            'weekly_cap_minutes' => 720,
            'monthly_cap_minutes' => null,
            'max_shift_minutes' => 960,
            'approval_mode' => self::APPROVE_AUTO,
            'pending_expiry_days' => 7,
            'pending_expiry_action' => self::EXPIRE_ESCALATE,
            'weekday_multiplier' => 1.50,
            'weekend_multiplier' => 2.00,
            'holiday_multiplier' => 2.00,
            'night_window_start' => null,
            'night_window_end' => null,
            'night_multiplier' => null,
            'rate_base' => self::RATE_GROSS,
            'offset_late_with_overtime' => false,
            'weekend_all_overtime' => true,
            'compensation_type' => self::COMP_PAID,
            'require_pre_approval' => false,
            'show_pay_to_employee' => true,
            'show_hours_to_employee' => true,
            'overtime_score_bonus_max' => 0,
        ];
    }
}
