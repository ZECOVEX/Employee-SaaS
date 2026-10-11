<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * §62 — Form Request for department creation. Tenant-scoped unique and
 * exists rules use the authenticated actor's organization.
 */
class StoreDepartmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('departments.manage');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $organizationId = $this->user()?->organization_id;

        return [
            'name' => ['required', 'string', 'max:120', Rule::unique('departments', 'name')
                ->where('organization_id', $organizationId)],
            'code' => ['nullable', 'string', 'max:40'],
            'description' => ['nullable', 'string', 'max:1000'],
            'manager_id' => ['nullable', Rule::exists('users', 'id')
                ->where('organization_id', $organizationId)],
        ];
    }
}
