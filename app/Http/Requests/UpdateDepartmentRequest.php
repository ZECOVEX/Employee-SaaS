<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * §62 — Form Request for department updates. Same tenant-scoped rules as
 * creation, with the unique rule ignoring the row being edited.
 */
class UpdateDepartmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('departments.manage');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $organizationId = $this->user()?->organization_id;
        $departmentId = $this->route('department')?->id;

        return [
            'name' => ['required', 'string', 'max:120', Rule::unique('departments', 'name')
                ->where('organization_id', $organizationId)
                ->ignore($departmentId)],
            'code' => ['nullable', 'string', 'max:40'],
            'description' => ['nullable', 'string', 'max:1000'],
            'manager_id' => ['nullable', Rule::exists('users', 'id')
                ->where('organization_id', $organizationId)],
        ];
    }
}
