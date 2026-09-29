<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EmployeeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'employee_code' => $this->employee_code,
            'name' => $this->user?->name,
            'email' => $this->user?->email,
            'status' => $this->status,
            'employment_type' => $this->employment_type,
            'department' => $this->whenLoaded('department', fn () => DepartmentResource::make($this->department)),
            'position' => $this->whenLoaded('position', fn () => $this->position?->title),
            'manager_id' => $this->manager_id,
            'phone' => $this->phone,
            'joining_date' => $this->joining_date?->toDateString(),
        ];
    }
}
