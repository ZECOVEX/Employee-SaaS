<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'organization_id',
    'plan_id',
    'status',
    'trial_ends_at',
    'current_period_start',
    'current_period_end',
])]
class Subscription extends Model
{
    use BelongsToOrganization;

    protected function casts(): array
    {
        return [
            'trial_ends_at' => 'date:Y-m-d',
            'current_period_start' => 'date:Y-m-d',
            'current_period_end' => 'date:Y-m-d',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function isActive(): bool
    {
        return in_array($this->status, ['trial', 'active'], true);
    }
}
