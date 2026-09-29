<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'organization_id',
    'plan_id',
    'number',
    'amount',
    'currency',
    'status',
    'period_start',
    'period_end',
    'paid_at',
])]
class Invoice extends Model
{
    use BelongsToOrganization;

    protected function casts(): array
    {
        return [
            'amount' => 'float',
            'period_start' => 'date:Y-m-d',
            'period_end' => 'date:Y-m-d',
            'paid_at' => 'date:Y-m-d',
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
}
