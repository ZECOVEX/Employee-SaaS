<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DailyAttendanceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'employee_id' => $this->employee_id,
            'date' => $this->date?->toDateString(),
            'status' => $this->status,
            'first_check_in' => $this->first_check_in?->toIso8601String(),
            'last_check_out' => $this->last_check_out?->toIso8601String(),
            'total_work_minutes' => $this->total_work_minutes,
            'late_minutes' => $this->late_minutes,
            'overtime_minutes' => $this->overtime_minutes,
            'review_flag' => $this->review_flag,
            'is_manual' => $this->is_manual,
        ];
    }
}
