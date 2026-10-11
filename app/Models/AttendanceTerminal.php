<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'organization_id',
    'name',
    'location',
    'latitude',
    'longitude',
    'radius_meters',
    'require_location',
    'allowed_ips',
    'key_hash',
    'status',
    'last_seen_at',
])]
class AttendanceTerminal extends Model
{
    use BelongsToOrganization;

    protected function casts(): array
    {
        return [
            'last_seen_at' => 'datetime',
            'latitude' => 'float',
            'longitude' => 'float',
            'radius_meters' => 'integer',
            'require_location' => 'boolean',
            'allowed_ips' => 'array',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(AttendanceEvent::class, 'terminal_id');
    }
}
