<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AttendanceEventResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'employee_id' => $this->employee_id,
            'event_type' => $this->event_type,
            'occurred_at' => $this->occurred_at?->toIso8601String(),
            'source' => $this->source,
            'terminal_id' => $this->terminal_id,
            'notes' => $this->notes,
            'superseded_by' => $this->superseded_by,
        ];
    }
}
