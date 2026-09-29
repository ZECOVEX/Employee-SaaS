<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One approval-trail row per overtime decision (§73-E).
 */
#[Fillable([
    'organization_id',
    'overtime_record_id',
    'approver_user_id',
    'decision',
    'minutes_before',
    'minutes_after',
    'reason',
    'decided_at',
])]
class OvertimeApproval extends Model
{
    use BelongsToOrganization;

    public const APPROVED = 'APPROVED';

    public const REJECTED = 'REJECTED';

    public const ADJUSTED = 'ADJUSTED';

    public const ESCALATED = 'ESCALATED';

    protected function casts(): array
    {
        return [
            'minutes_before' => 'integer',
            'minutes_after' => 'integer',
            'decided_at' => 'datetime',
        ];
    }

    public function record(): BelongsTo
    {
        return $this->belongsTo(OvertimeRecord::class, 'overtime_record_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approver_user_id');
    }
}
