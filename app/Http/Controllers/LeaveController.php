<?php

namespace App\Http\Controllers;

use App\Models\DailyAttendance;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Services\AuditLogger;
use App\Services\LeaveService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class LeaveController extends Controller
{
    public function __construct(
        private readonly LeaveService $leave,
        private readonly AuditLogger $audit,
    ) {}

    public function index(Request $request): View
    {
        Gate::authorize('leave.view');

        $user = $request->user();
        $status = $request->string('status')->toString();

        $query = LeaveRequest::with(['employee.user:id,name', 'leaveType:id,name,code', 'reviewer:id,name'])
            ->when($status !== '', fn ($q) => $q->where('status', $status))
            ->orderByDesc('created_at');

        // Employees without leave.manage only see their own requests.
        if (! $user->canPermission('leave.manage') && ! $user->canPermission('leave.approve')) {
            $query->whereHas('employee', fn ($e) => $e->where('user_id', $user->id));
        }

        // Managers only approve their own team when they lack leave.manage.
        if ($user->canPermission('leave.approve') && ! $user->canPermission('leave.manage')) {
            $query->where(function ($q) use ($user) {
                $q->whereHas('employee', fn ($e) => $e->where('user_id', $user->id))
                    ->orWhereHas('employee', fn ($e) => $e->where('manager_id', $user->id));
            });
        }

        $requests = $query->paginate(20)->withQueryString();

        return view('leave.index', compact('requests', 'status'));
    }

    public function create(Request $request): View
    {
        $employee = Employee::where('user_id', $request->user()->id)->firstOrFail();

        $this->leave->ensureBalances($employee);

        $balances = LeaveBalance::with('leaveType:id,name,code')
            ->where('employee_id', $employee->id)
            ->where('year', (int) now()->year)
            ->get();

        $types = LeaveType::where('is_active', true)->orderBy('name')->get(['id', 'name', 'code']);

        return view('leave.create', compact('employee', 'balances', 'types'));
    }

    public function store(Request $request): RedirectResponse
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
            \Carbon\Carbon::parse($data['start_date']),
            \Carbon\Carbon::parse($data['end_date']),
        );

        if ($days < 1) {
            return back()->withErrors(['end_date' => 'Range contains no working days.']);
        }

        $type = LeaveType::findOrFail($data['leave_type_id']);

        if ($type->default_days_per_year > 0) {
            $this->leave->ensureBalances($employee);
            $balance = LeaveBalance::where('employee_id', $employee->id)
                ->where('leave_type_id', $type->id)
                ->where('year', (int) now()->year)
                ->first();

            if ($balance && $balance->used_days + $days > $balance->total_days) {
                return back()->withErrors([
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

        return redirect()
            ->route('leave.index')
            ->with('status', 'Leave request submitted.');
    }

    public function approve(Request $request, LeaveRequest $leaveRequest): RedirectResponse
    {
        Gate::authorize('leave.approve');
        $this->authorizeReview($request, $leaveRequest);

        try {
            $this->leave->approve($leaveRequest, $request->user()->id, $request->input('review_note'));
        } catch (\RuntimeException $e) {
            return back()->withErrors(['leave' => $e->getMessage()]);
        }

        return back()->with('status', 'Leave approved.');
    }

    public function reject(Request $request, LeaveRequest $leaveRequest): RedirectResponse
    {
        Gate::authorize('leave.approve');
        $this->authorizeReview($request, $leaveRequest);

        try {
            $this->leave->reject($leaveRequest, $request->user()->id, $request->input('review_note'));
        } catch (\RuntimeException $e) {
            return back()->withErrors(['leave' => $e->getMessage()]);
        }

        return back()->with('status', 'Leave rejected.');
    }

    public function cancel(Request $request, LeaveRequest $leaveRequest): RedirectResponse
    {
        $employee = Employee::where('user_id', $request->user()->id)->first();

        if ($leaveRequest->employee_id !== $employee?->id) {
            abort(403);
        }

        try {
            $this->leave->cancel($leaveRequest);
        } catch (\RuntimeException $e) {
            return back()->withErrors(['leave' => $e->getMessage()]);
        }

        return back()->with('status', 'Leave request cancelled.');
    }

    private function authorizeReview(Request $request, LeaveRequest $leaveRequest): void
    {
        $user = $request->user();

        if ($user->canPermission('leave.manage') || $user->is_platform_admin) {
            return;
        }

        $ownsTeam = DB::table('employees')
            ->where('id', $leaveRequest->employee_id)
            ->where('manager_id', $user->id)
            ->exists();

        abort_unless($ownsTeam, 403);
    }
}
