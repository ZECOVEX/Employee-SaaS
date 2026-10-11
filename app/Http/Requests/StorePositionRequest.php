<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * §62 — Form Request for position (designation) creation. Tenant-scoped
 * unique title and org-scoped department reference.
 */
class StorePositionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('positions.manage');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $organizationId = $this->user()?->organization_id;

        return [
            'title' => ['required', 'string', 'max:120', Rule::unique('positions', 'title')
                ->where('organization_id', $organizationId)],
            'department_id' => ['nullable', Rule::exists('departments', 'id')
                ->where('organization_id', $organizationId)],
        ];
    }
}
