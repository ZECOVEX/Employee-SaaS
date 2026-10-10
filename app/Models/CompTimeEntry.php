<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * §73-F COMP_TIME ledger entry: compensatory time accrued from one approved
 * overtime record. Kept idempotent by a unique overtime_record_id — the
 * OvertimeService upserts or deletes it whenever the record's decision or
 * minutes change.
 */
#[Fillable([
    'organization_id',
    'employee_id',
    'overtime_record_id',
    'work_date',
    'minutes',
])]
class CompTimeEntry extends Model
{
    use BelongsToOrganization;

    protected function casts(): array
    {
        return [
            'work_date' => 'date:Y-m-d',
            'minutes' => 'integer',
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

    public function overtimeRecord(): BelongsTo
    {
        return $this->belongsTo(OvertimeRecord::class, 'overtime_record_id');
    }

    /** "2h 10m" / "45m" balance label for cards and tables. */
    public static function minutesLabel(int $minutes): string
    {
        if ($minutes <= 0) {
            return '0m';
        }

        $hours = intdiv($minutes, 60);
        $mins = $minutes % 60;

        if ($hours === 0) {
            return $mins.'m';
        }

        return $mins === 0 ? $hours.'h' : $hours.'h '.$mins.'m';
    }
}
