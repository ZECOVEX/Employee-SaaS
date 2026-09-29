<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\DepartmentResource;
use App\Models\Department;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class DepartmentController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        Gate::authorize('departments.view');

        return DepartmentResource::collection(
            Department::orderBy('name')->get(['id', 'name', 'code']),
        );
    }
}
