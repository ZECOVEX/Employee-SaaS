<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\AttendanceEventResource;
use App\Http\Resources\DailyAttendanceResource;
use App\Models\AttendanceEvent;
use App\Models\DailyAttendance;
use App\Models\Employee;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class AttendanceController extends Controller
{
    /** Aggregated daily records (permission: attendance.view). */
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('attendance.view');

        $query = DailyAttendance::with('employee:id,user_id')
            ->when($request->filled('from'), fn ($q) => $q->whereDate('date', '>=', $request->date('from')))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('date', '<=', $request->date('to')))
            ->when($request->filled('employee_id'), fn ($q) => $q->where('employee_id', $request->integer('employee_id')))
            ->orderByDesc('date');

        return DailyAttendanceResource::collection(
            $query->paginate(min(max($request->integer('per_page', 15), 1), 100))->withQueryString(),
        );
    }

    /** Raw punch events (permission: attendance.view). */
    public function events(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('attendance.view');

        $query = AttendanceEvent::query()
            ->when($request->filled('from'), fn ($q) => $q->whereDate('occurred_at', '>=', $request->date('from')))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('occurred_at', '<=', $request->date('to')))
            ->when($request->filled('employee_id'), fn ($q) => $q->where('employee_id', $request->integer('employee_id')))
            ->orderByDesc('occurred_at');

        return AttendanceEventResource::collection(
            $query->paginate(min(max($request->integer('per_page', 15), 1), 100))->withQueryString(),
        );
    }

    /** The caller's own daily records — always allowed (own data). */
    public function mine(Request $request): AnonymousResourceCollection
    {
        $employee = Employee::where('user_id', $request->user()->id)->firstOrFail();

        return DailyAttendanceResource::collection(
            DailyAttendance::where('employee_id', $employee->id)
                ->orderByDesc('date')
                ->paginate(31),
        );
    }
}
