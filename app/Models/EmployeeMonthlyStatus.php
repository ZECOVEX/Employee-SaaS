<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Tenancy\BelongsToOrganization;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * §17 finalized monthly snapshot: attendance score + components, day
 * counts, worked/expected minutes and overtime totals for one employee-
 * month. Written once by MonthlyStatusService and never updated.
 */
#[Fillable([
    'organization_id',
    'employee_id',
    'year',
    'month',
    'attendance_score',
    'attendance_rate',
    'punctuality_score',
    'working_hours_score',
    'leave_score',
    'days_present',
    'days_absent',
    'days_late',
    'days_on_leave',
    'total_work_minutes',
    'expected_work_minutes',
    'overtime_minutes_approved',
    'overtime_minutes_pending',
    'overtime_minutes_rejected',
    'overtime_days',
    'status',
    'generated_at',
])]
class EmployeeMonthlyStatus extends Model
{
    use BelongsToOrganization;

    public const STATUS_FINALIZED = 'finalized';

    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'month' => 'integer',
            'attendance_score' => 'float',
            'attendance_rate' => 'float',
            'punctuality_score' => 'float',
            'working_hours_score' => 'float',
            'leave_score' => 'float',
            'days_present' => 'integer',
            'days_absent' => 'integer',
            'days_late' => 'integer',
            'days_on_leave' => 'integer',
            'total_work_minutes' => 'integer',
            'expected_work_minutes' => 'integer',
            'overtime_minutes_approved' => 'integer',
            'overtime_minutes_pending' => 'integer',
            'overtime_minutes_rejected' => 'integer',
            'overtime_days' => 'integer',
            'generated_at' => 'datetime',
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

    /** Key for lookups: "2026-09". */
    public function periodKey(): string
    {
        return sprintf('%04d-%02d', $this->year, $this->month);
    }

    public function periodLabel(): string
    {
        return Carbon::createFromDate($this->year, $this->month, 1)->format('M Y');
    }
}
