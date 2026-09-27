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
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * @return array<int>
     */
    public function workDayNumbers(): array
    {
        return $this->work_days ?? [1, 2, 3, 4, 5];
    }
}
