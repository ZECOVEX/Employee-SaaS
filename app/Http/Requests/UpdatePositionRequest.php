<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * §62 — Form Request for position updates. Same tenant-scoped rules as
 * creation, with the unique title ignoring the row being edited.
 */
class UpdatePositionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('positions.manage');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $organizationId = $this->user()?->organization_id;
        $positionId = $this->route('position')?->id;

        return [
            'title' => ['required', 'string', 'max:120', Rule::unique('positions', 'title')
                ->where('organization_id', $organizationId)
                ->ignore($positionId)],
            'department_id' => ['nullable', Rule::exists('departments', 'id')
                ->where('organization_id', $organizationId)],
        ];
    }
}
