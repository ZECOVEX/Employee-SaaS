<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'organization_id',
    'employee_id',
    'basic_salary',
    'allowances',
    'bonus',
    'deductions',
    'gross_salary',
    'net_salary',
    'effective_from',
    'effective_to',
    'notes',
    'created_by',
])]
class SalaryRecord extends Model
{
    use BelongsToOrganization;

    protected function casts(): array
    {
        return [
            'basic_salary' => 'float',
            'allowances' => 'float',
            'bonus' => 'float',
            'deductions' => 'float',
            'gross_salary' => 'float',
            'net_salary' => 'float',
            'effective_from' => 'date:Y-m-d',
            'effective_to' => 'date:Y-m-d',
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

    /**
     * The salary version in effect for a given date (effective dating, §28).
     * History is never overwritten — a raise closes the old range instead.
     */
    public static function effectiveFor(int $organizationId, int $employeeId, string $date): ?self
    {
        return self::withoutGlobalScopes()
            ->where('organization_id', $organizationId)
            ->where('employee_id', $employeeId)
            ->where('effective_from', '<=', $date)
            ->where(function ($q) use ($date) {
                $q->whereNull('effective_to')->orWhere('effective_to', '>=', $date);
            })
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * The same effective-dating rule for every employee at once, keyed by
     * employee_id (latest effective_from wins — identical ordering to
     * effectiveFor()). List/report pages use this instead of one query per
     * employee.
     *
     * @return array<int, self|null>
     */
    public static function effectiveForDate(int $organizationId, string $date): array
    {
        $rows = self::withoutGlobalScopes()
            ->where('organization_id', $organizationId)
            ->where('effective_from', '<=', $date)
            ->where(function ($q) use ($date) {
                $q->whereNull('effective_to')->orWhere('effective_to', '>=', $date);
            })
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->get();

        $byEmployee = [];
        foreach ($rows as $record) {
            $employeeId = (int) $record->employee_id;
            if (! array_key_exists($employeeId, $byEmployee)) {
                $byEmployee[$employeeId] = $record;
            }
        }

        return $byEmployee;
    }

    /**
     * Recompute derived money columns from the base amounts.
     * gross = basic + allowances + bonus; net = gross − deductions.
     */
    public static function computeAmounts(float $basic, float $allowances, float $bonus, float $deductions): array
    {
        $gross = $basic + $allowances + $bonus;

        return [
            'gross_salary' => round($gross, 2),
            'net_salary' => round($gross - $deductions, 2),
        ];
    }
}
