<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\EmployeeResource;
use App\Models\Employee;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class EmployeeController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('employees.view');

        $user = $request->user();

        $employees = Employee::with(['user:id,name,email', 'department:id,name,code', 'position:id,title'])
            ->when(! $user->is_platform_admin && $user->hasRole('manager') && ! $user->hasAnyRole(['company_admin', 'hr']),
                fn ($q) => $q->where('manager_id', $user->id))
            ->when($request->filled('q'), function ($q) use ($request) {
                $term = $request->string('q')->toString();
                $q->where(function ($w) use ($term) {
                    $w->where('employee_code', 'like', "%{$term}%")
                        ->orWhereHas('user', fn ($u) => $u->where('name', 'like', "%{$term}%")
                            ->orWhere('email', 'like', "%{$term}%"));
                });
            })
            ->when($request->filled('department_id'), fn ($q) => $q->where('department_id', $request->integer('department_id')))
            ->orderBy('employee_code')
            ->paginate(min(max($request->integer('per_page', 15), 1), 100))
            ->withQueryString();

        return EmployeeResource::collection($employees);
    }

    public function show(Employee $employee): EmployeeResource
    {
        Gate::authorize('employees.view');

        return new EmployeeResource($employee->load([
            'user:id,name,email',
            'department:id,name,code',
            'position:id,title',
        ]));
    }
}
