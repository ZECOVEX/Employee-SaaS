<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SalaryRecordResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'employee_id' => $this->employee_id,
            'currency' => $this->organization?->currency(),
            'basic_salary' => $this->basic_salary,
            'allowances' => $this->allowances,
            'bonus' => $this->bonus,
            'deductions' => $this->deductions,
            'gross_salary' => $this->gross_salary,
            'net_salary' => $this->net_salary,
            'effective_from' => $this->effective_from?->toDateString(),
            'effective_to' => $this->effective_to?->toDateString(),
        ];
    }
}
