<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'organization_id',
    'name',
    'start_time',
    'end_time',
    'grace_minutes',
    'break_start',
    'break_end',
    'work_days',
    'is_default',
    'effective_from',
    'effective_to',
])]
class WorkSchedule extends Model
{
    use BelongsToOrganization;

    protected function casts(): array
    {
        return [
            'work_days' => 'array',
            'is_default' => 'boolean',
            'grace_minutes' => 'integer',
            'effective_from' => 'date:Y-m-d',
            'effective_to' => 'date:Y-m-d',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * The schedule version in effect for a given date (effective dating, §11).
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
            ->orderByDesc('is_default')
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Whether this schedule version covers the given date.
     */
    public function covers(string $date): bool
    {
        if ($this->effective_from && $this->effective_from->toDateString() > $date) {
            return false;
        }

        if ($this->effective_to && $this->effective_to->toDateString() < $date) {
            return false;
        }

        return true;
    }

    /**
     * @return array<int>
     */
    public function workDayNumbers(): array
    {
        return $this->work_days ?? [1, 2, 3, 4, 5];
    }
}
