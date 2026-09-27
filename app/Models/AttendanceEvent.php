<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'organization_id',
    'employee_id',
    'terminal_id',
    'nfc_card_id',
    'event_type',
    'occurred_at',
    'timezone',
    'ip_address',
    'device_identifier',
    'source',
    'notes',
    'created_by',
])]
class AttendanceEvent extends Model
{
    use BelongsToOrganization;

    public const CHECK_IN = 'CHECK_IN';
    public const CHECK_OUT = 'CHECK_OUT';
    public const BREAK_START = 'BREAK_START';
    public const BREAK_END = 'BREAK_END';
    public const MANUAL_IN = 'MANUAL_IN';
    public const MANUAL_OUT = 'MANUAL_OUT';

    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
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

    public function terminal(): BelongsTo
    {
        return $this->belongsTo(AttendanceTerminal::class, 'terminal_id');
    }

    public function card(): BelongsTo
    {
        return $this->belongsTo(NfcCard::class, 'nfc_card_id');
    }
}
