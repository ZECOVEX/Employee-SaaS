<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * §15 risk event: a configured threshold was reached for one employee in
 * one evaluation window (weekly late-risk, [OT] overwork limits). Created
 * by RiskService; drives Statistics/Dashboard alerts and suppression.
 */
#[Fillable([
    'organization_id',
    'employee_id',
    'type',
    'level',
    'period_start',
    'period_end',
    'observed',
    'threshold',
    'detail',
    'notified_at',
])]
class RiskEvent extends Model
{
    use BelongsToOrganization;

    public const TYPE_LATE_WEEK = 'late_week';

    public const TYPE_OT_WEEK = 'ot_week';

    public const TYPE_OT_MONTH = 'ot_month';

    public const TYPE_OT_STREAK = 'ot_streak';

    protected function casts(): array
    {
        return [
            'period_start' => 'date:Y-m-d',
            'period_end' => 'date:Y-m-d',
            'observed' => 'integer',
            'threshold' => 'integer',
            'notified_at' => 'datetime',
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

    /** True when the event's window contains the given date (dashboard banner). */
    public function covers(string $date): bool
    {
        return $date >= $this->period_start->toDateString()
            && $date <= $this->period_end->toDateString();
    }

    public function typeLabel(): string
    {
        return match ($this->type) {
            self::TYPE_LATE_WEEK => 'Weekly late risk',
            self::TYPE_OT_WEEK => 'Weekly overtime limit',
            self::TYPE_OT_MONTH => 'Monthly overtime limit',
            self::TYPE_OT_STREAK => 'Consecutive overtime days',
            default => $this->type,
        };
    }

    /** Period label for notifications ("this week" / "this month"). */
    public function windowLabel(): string
    {
        if ($this->type === self::TYPE_OT_MONTH) {
            return 'this month';
        }

        if ($this->type === self::TYPE_LATE_WEEK && $this->period_end->diffInDays($this->period_start) > 6) {
            return 'in the last 7 days';
        }

        return 'this week';
    }
}
