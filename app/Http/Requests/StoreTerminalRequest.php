<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * §62/§32 — Form Request for attendance-terminal registration: identity,
 * optional GPS pin + verification radius, and the optional IP allowlist.
 */
class StoreTerminalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('attendance.terminal');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $organizationId = $this->user()?->organization_id;

        return [
            'name' => ['required', 'string', 'max:120', Rule::unique('attendance_terminals', 'name')
                ->where('organization_id', $organizationId)],
            'location' => ['nullable', 'string', 'max:160'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90', 'required_with:longitude,require_location'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180', 'required_with:latitude,require_location'],
            'radius_meters' => ['nullable', 'integer', 'min:10', 'max:10000'],
            'require_location' => ['nullable', 'boolean'],
            'allowed_ips' => ['nullable', 'array', 'max:20'],
            'allowed_ips.*' => ['string', 'max:50'],
        ];
    }
}
