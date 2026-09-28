<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Finalized monthly payroll record (§28): a frozen snapshot of the salary
 * calculation that never changes once written. Corrections create a new
 * revision that supersedes the old one — history is preserved, never
 * overwritten (same rule as salary records and attendance events).
 */
#[Fillable([
    'organization_id',
    'employee_id',
    'period',
    'period_start',
    'period_end',
    'revision',
    'currency',
    'basic_salary',
    'allowances',
    'bonus',
    'other_deductions',
    'gross_salary',
    'late_deduction',
    'absence_deduction',
    'unpaid_leave_deduction',
    'attendance_deduction_total',
    'net_salary',
    'scheduled_days',
    'completed_days',
    'worked_minutes',
    'late_minutes',
    'overtime_minutes',
    'daily_rate',
    'hourly_rate',
    'capped',
    'snapshot',
    'notes',
    'superseded_by',
    'created_by',
])]
class Payslip extends Model
{
    use BelongsToOrganization;

    protected function casts(): array
    {
        return [
            'period_start' => 'date:Y-m-d',
            'period_end' => 'date:Y-m-d',
            'revision' => 'integer',
            'basic_salary' => 'float',
            'allowances' => 'float',
            'bonus' => 'float',
            'other_deductions' => 'float',
            'gross_salary' => 'float',
            'late_deduction' => 'float',
            'absence_deduction' => 'float',
            'unpaid_leave_deduction' => 'float',
            'attendance_deduction_total' => 'float',
            'net_salary' => 'float',
            'scheduled_days' => 'integer',
            'completed_days' => 'integer',
            'worked_minutes' => 'integer',
            'late_minutes' => 'integer',
            'overtime_minutes' => 'integer',
            'daily_rate' => 'float',
            'hourly_rate' => 'float',
            'capped' => 'boolean',
            'snapshot' => 'array',
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

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function supersededBy(): BelongsTo
    {
        return $this->belongsTo(Payslip::class, 'superseded_by');
    }

    /**
     * All revisions of this employee's payslip for the same period, oldest first.
     *
     * @return HasMany<Payslip, $this>
     */
    public function revisions(): HasMany
    {
        return $this->hasMany(Payslip::class, 'employee_id', 'employee_id')
            ->where('period', $this->period)
            ->orderBy('revision');
    }

    public function isSuperseded(): bool
    {
        return $this->superseded_by !== null;
    }

    public function periodLabel(): string
    {
        return $this->period_start->format('M Y');
    }
}
