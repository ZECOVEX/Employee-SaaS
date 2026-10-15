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
    'card_token',
    'employee_id',
    'status',
    'issued_at',
    'revoked_at',
    'last_used_at',
    'notes',
])]
class NfcCard extends Model
{
    use BelongsToOrganization;

    /**
     * Canonical form of a captured card code: uppercase with whitespace
     * and colons stripped, so `04:6e 3a` and `046E3A` resolve to the same
     * card (phone NFC / reader machines emit either shape).
     */
    public static function normalizeToken(string $token): string
    {
        return strtoupper((string) preg_replace('/[\s:]+/', '', trim($token)));
    }

    protected function casts(): array
    {
        return [
            'issued_at' => 'datetime',
            'revoked_at' => 'datetime',
            'last_used_at' => 'datetime',
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

    public function events(): HasMany
    {
        return $this->hasMany(AttendanceEvent::class);
    }
}
