<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'organization_id',
    'employee_id',
    'date',
    'first_check_in',
    'last_check_out',
    'total_work_minutes',
    'late_minutes',
    'early_leave_minutes',
    'overtime_minutes',
    'status',
    'is_manual',
])]
class DailyAttendance extends Model
{
    use BelongsToOrganization;

    /** Table name is singular by spec (daily_attendance). */
    protected $table = 'daily_attendance';

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'first_check_in' => 'datetime',
            'last_check_out' => 'datetime',
            'total_work_minutes' => 'integer',
            'late_minutes' => 'integer',
            'early_leave_minutes' => 'integer',
            'overtime_minutes' => 'integer',
            'is_manual' => 'boolean',
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
}
