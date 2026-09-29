<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Platform-wide plan catalog (§54) — intentionally NOT tenant-scoped:
 * every organization selects from the same plans.
 */
#[Fillable([
    'slug',
    'name',
    'employee_limit',
    'price_monthly',
    'is_active',
])]
class Plan extends Model
{
    protected function casts(): array
    {
        return [
            'employee_limit' => 'integer',
            'price_monthly' => 'float',
            'is_active' => 'boolean',
        ];
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    public function isUnlimited(): bool
    {
        return $this->employee_limit === null;
    }

    public function priceLabel(): string
    {
        return $this->price_monthly > 0
            ? '$'.number_format($this->price_monthly, 2)
            : 'Free';
    }
}
