<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\LeaveRequestResource;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Services\AuditLogger;
use App\Services\LeaveService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class LeaveController extends Controller
{
    public function __construct(
        private readonly LeaveService $leave,
        private readonly AuditLogger $audit,
    ) {}

    /** All visible leave requests (permission: leave.view, same scoping as the web UI). */
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('leave.view');

        $user = $request->user();
        $status = $request->string('status')->toString();

        $query = LeaveRequest::with(['employee.user:id,name', 'leaveType:id,name,code', 'reviewer:id,name'])
            ->when($status !== '', fn ($q) => $q->where('status', $status))
            ->orderByDesc('created_at');

        if (! $user->canPermission('leave.manage') && ! $user->canPermission('leave.approve')) {
            $query->whereHas('employee', fn ($e) => $e->where('user_id', $user->id));
        }

        if ($user->canPermission('leave.approve') && ! $user->canPermission('leave.manage')) {
            $query->where(function ($q) use ($user) {
                $q->whereHas('employee', fn ($e) => $e->where('user_id', $user->id))
                    ->orWhereHas('employee', fn ($e) => $e->where('manager_id', $user->id));
            });
        }

        return LeaveRequestResource::collection(
            $query->paginate(min(max($request->integer('per_page', 20), 1), 100))->withQueryString(),
        );
    }

    /** The caller's own requests — always allowed. */
    public function mine(Request $request): AnonymousResourceCollection
    {
        $employee = Employee::where('user_id', $request->user()->id)->firstOrFail();

        return LeaveRequestResource::collection(
            LeaveRequest::with(['leaveType:id,name,code'])
                ->where('employee_id', $employee->id)
                ->orderByDesc('created_at')
                ->paginate(50),
        );
    }

    /** Submit a leave request for the caller (mirrors the web form, §19). */
    public function store(Request $request): JsonResponse
    {
        $employee = Employee::where('user_id', $request->user()->id)->firstOrFail();

        $data = $request->validate([
            'leave_type_id' => ['required', Rule::exists('leave_types', 'id')
                ->where('organization_id', $request->user()->organization_id)],
            'start_date' => ['required', 'date', 'after_or_equal:today'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        $days = $this->leave->workingDays(
            Carbon::parse($data['start_date']),
            Carbon::parse($data['end_date']),
        );

        if ($days < 1) {
            throw ValidationException::withMessages([
                'end_date' => 'Range contains no working days.',
            ]);
        }

        $type = LeaveType::findOrFail($data['leave_type_id']);

        if ($type->default_days_per_year > 0) {
            $this->leave->ensureBalances($employee);
            $balance = LeaveBalance::where('employee_id', $employee->id)
                ->where('leave_type_id', $type->id)
                ->where('year', (int) now()->year)
                ->first();

            if ($balance && $balance->used_days + $days > $balance->total_days) {
                throw ValidationException::withMessages([
                    'leave_type_id' => sprintf(
                        'Insufficient balance: %d of %d days remaining.',
                        $balance->remainingDays(),
                        $balance->total_days,
                    ),
                ]);
            }
        }

        $requestModel = LeaveRequest::create([
            'organization_id' => $request->user()->organization_id,
            'employee_id' => $employee->id,
            'leave_type_id' => $type->id,
            'start_date' => $data['start_date'],
            'end_date' => $data['end_date'],
            'days' => $days,
            'reason' => $data['reason'] ?? null,
            'status' => LeaveRequest::PENDING,
        ]);

        $this->audit->log('leave.requested', $requestModel, null, $requestModel->only([
            'leave_type_id', 'start_date', 'end_date', 'days',
        ]));

        return (new LeaveRequestResource($requestModel->load('leaveType:id,name,code')))
            ->response()
            ->setStatusCode(201);
    }
}
