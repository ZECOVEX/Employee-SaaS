<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Derived overtime for one employee-day (§73-H). Never written from requests:
 * only OvertimeService computes minutes, pay and status.
 */
#[Fillable([
    'organization_id',
    'employee_id',
    'work_date',
    'day_type',
    'policy_id',
    'worked_minutes',
    'expected_minutes',
    'raw_minutes',
    'late_offset_minutes',
    'countable_minutes',
    'multiplier',
    'hourly_rate_snapshot',
    'estimated_pay',
    'status',
    'flag_reason',
    'employee_note',
    'calculated_at',
])]
class OvertimeRecord extends Model
{
    use BelongsToOrganization;

    public const STATUS_NONE = 'NONE';

    public const STATUS_PENDING = 'PENDING';

    public const STATUS_AUTO_APPROVED = 'AUTO_APPROVED';

    public const STATUS_APPROVED = 'APPROVED';

    public const STATUS_REJECTED = 'REJECTED';

    public const STATUS_FLAGGED = 'FLAGGED';

    public const DAY_WORKING = 'WORKING_DAY';

    public const DAY_WEEKEND = 'WEEKEND';

    public const DAY_HOLIDAY = 'HOLIDAY';

    public const DAY_LEAVE = 'LEAVE_DAY';

    protected function casts(): array
    {
        return [
            'work_date' => 'date:Y-m-d',
            'worked_minutes' => 'integer',
            'expected_minutes' => 'integer',
            'raw_minutes' => 'integer',
            'late_offset_minutes' => 'integer',
            'countable_minutes' => 'integer',
            'multiplier' => 'float',
            'hourly_rate_snapshot' => 'float',
            'estimated_pay' => 'float',
            'calculated_at' => 'datetime',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function policy(): BelongsTo
    {
        return $this->belongsTo(OvertimePolicy::class, 'policy_id');
    }

    public function approvals(): HasMany
    {
        return $this->hasMany(OvertimeApproval::class);
    }

    /** Approved decisions are locked (§73-E); changes go through adjust. */
    public function isLocked(): bool
    {
        return in_array($this->status, [self::STATUS_APPROVED, self::STATUS_REJECTED], true);
    }

    public function hasCountableOvertime(): bool
    {
        return $this->countable_minutes > 0
            && in_array($this->status, [
                self::STATUS_PENDING,
                self::STATUS_AUTO_APPROVED,
                self::STATUS_APPROVED,
            ], true);
    }

    /** "2h 10m" / "45m" label for cards and tables. */
    public function minutesLabel(?int $minutes = null): string
    {
        $minutes = $minutes ?? $this->countable_minutes;

        if ($minutes <= 0) {
            return '0m';
        }

        $hours = intdiv($minutes, 60);
        $mins = $minutes % 60;

        if ($hours === 0) {
            return $mins.'m';
        }

        return $mins === 0 ? $hours.'h' : $hours.'h '.$mins.'m';
    }
}
