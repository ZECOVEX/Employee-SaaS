<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveNotice;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Notifications\LeaveNoticeAdded;
use App\Services\AuditLogger;
use App\Services\LeaveService;
use App\Services\Webhooks\WebhookEvent;
use App\Services\Webhooks\WebhookService;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

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
            'attachment' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png,webp', 'max:2048'],
        ]);

        $days = $this->leave->workingDays(
            Carbon::parse($data['start_date']),
            Carbon::parse($data['end_date']),
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

        if ($request->hasFile('attachment')) {
            $path = $request->file('attachment')
                ->store('leave-attachments/'.$request->user()->organization_id, 'local');
            $requestModel->update(['attachment_path' => $path]);
        }

        $this->audit->log('leave.requested', $requestModel, null, $requestModel->only([
            'leave_type_id', 'start_date', 'end_date', 'days', 'attachment_path',
        ]));

        return redirect()
            ->route('leave.index')
            ->with('status', 'Leave request submitted.');
    }

    /**
     * Stream a leave attachment from the private disk. Visibility mirrors
     * leave.index: leave.manage sees everything, approve-only reviewers
     * their team, everyone else their own requests.
     */
    public function attachment(Request $request, LeaveRequest $leaveRequest): StreamedResponse
    {
        abort_unless($leaveRequest->attachment_path !== null, 404);

        $user = $request->user();

        if (! $user->canPermission('leave.manage') && ! $user->is_platform_admin) {
            $owns = $leaveRequest->employee?->user_id === $user->id;
            $team = $user->canPermission('leave.approve')
                && DB::table('employees')
                    ->where('id', $leaveRequest->employee_id)
                    ->where('manager_id', $user->id)
                    ->exists();

            abort_unless($owns || $team, 403);
        }

        abort_unless(Storage::disk('local')->exists($leaveRequest->attachment_path), 404);

        return Storage::disk('local')->download(
            $leaveRequest->attachment_path,
            sprintf('leave-request-%d.%s', $leaveRequest->id, pathinfo($leaveRequest->attachment_path, PATHINFO_EXTENSION)),
        );
    }

    public function approve(Request $request, LeaveRequest $leaveRequest): RedirectResponse
    {
        Gate::authorize('leave.approve');
        $this->authorizeReview($request, $leaveRequest);

        $request->validate([
            'review_note' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $this->leave->approve($leaveRequest, $request->user()->id, $request->input('review_note'));
        } catch (\RuntimeException $e) {
            return back()->withErrors(['leave' => $e->getMessage()]);
        }

        app(WebhookService::class)->dispatch(
            WebhookEvent::LEAVE_APPROVED,
            (int) $leaveRequest->organization_id,
            ['leave_request_id' => $leaveRequest->id, 'employee_id' => $leaveRequest->employee_id],
        );

        return back()->with('status', 'Leave approved.');
    }

    public function reject(Request $request, LeaveRequest $leaveRequest): RedirectResponse
    {
        Gate::authorize('leave.approve');
        $this->authorizeReview($request, $leaveRequest);

        $request->validate([
            'review_note' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $this->leave->reject($leaveRequest, $request->user()->id, $request->input('review_note'));
        } catch (\RuntimeException $e) {
            return back()->withErrors(['leave' => $e->getMessage()]);
        }

        app(WebhookService::class)->dispatch(
            WebhookEvent::LEAVE_REJECTED,
            (int) $leaveRequest->organization_id,
            ['leave_request_id' => $leaveRequest->id, 'employee_id' => $leaveRequest->employee_id],
        );

        return back()->with('status', 'Leave rejected.');
    }

    /**
     * Leave detail page: request info, review history, and the notice
     * thread. Visible to the owner and to anyone who can review the request
     * (leave.manage, or leave.approve for their own team).
     */
    public function show(Request $request, LeaveRequest $leaveRequest): View
    {
        Gate::authorize('leave.view');

        $isOwner = $leaveRequest->employee?->user_id === $request->user()->id;
        $canReview = $this->canReview($request, $leaveRequest);

        abort_unless($isOwner || $canReview, 403);

        $leaveRequest->load([
            'employee.user:id,name,email',
            'leaveType:id,name,code',
            'reviewer:id,name',
            'notices',
            'notices.author:id,name',
        ]);

        return view('leave.show', compact('leaveRequest', 'canReview', 'isOwner'));
    }

    /**
     * Append a notice to the request's thread. The employee sees every
     * notice on the show page and receives a notification.
     */
    public function addNotice(Request $request, LeaveRequest $leaveRequest): RedirectResponse
    {
        Gate::authorize('leave.approve');
        $this->authorizeReview($request, $leaveRequest);

        $data = $request->validate([
            'body' => ['required', 'string', 'max:2000'],
        ]);

        $notice = LeaveNotice::create([
            'organization_id' => $leaveRequest->organization_id,
            'leave_request_id' => $leaveRequest->id,
            'user_id' => $request->user()->id,
            'body' => $data['body'],
        ]);

        $this->audit->log('leave.noticed', $leaveRequest, null, [
            'leave_notice_id' => $notice->id,
            'body' => $data['body'],
        ]);

        $leaveRequest->employee?->user?->notify(new LeaveNoticeAdded(
            (int) $leaveRequest->id,
            $leaveRequest->leaveType->name,
            $request->user()->name,
            $data['body'],
        ));

        return redirect()
            ->route('leave.show', $leaveRequest)
            ->with('status', 'Notice added. The employee can now see it.');
    }

    /**
     * Edit form — reviewers may change the type, dates or reason of any
     * non-cancelled request, including after approval (reduce or extend the
     * range at any time, past dates included).
     */
    public function edit(Request $request, LeaveRequest $leaveRequest): View
    {
        Gate::authorize('leave.approve');
        $this->authorizeReview($request, $leaveRequest);

        abort_if(
            $leaveRequest->status === LeaveRequest::CANCELLED,
            403,
            'A cancelled request cannot be edited.',
        );

        $types = LeaveType::where('is_active', true)->orderBy('name')->get(['id', 'name', 'code']);

        return view('leave.edit', [
            'leaveRequest' => $leaveRequest->load(['employee.user:id,name,email', 'leaveType:id,name,code']),
            'types' => $types,
        ]);
    }

    /**
     * Save the edit. Days are recomputed from the range (never taken from
     * the input) and, for approved requests, LeaveService::edit()
     * reconciles the balance and moves the LEAVE attendance stamps.
     */
    public function update(Request $request, LeaveRequest $leaveRequest): RedirectResponse
    {
        Gate::authorize('leave.approve');
        $this->authorizeReview($request, $leaveRequest);

        abort_if(
            $leaveRequest->status === LeaveRequest::CANCELLED,
            403,
            'A cancelled request cannot be edited.',
        );

        $data = $request->validate([
            'leave_type_id' => ['required', Rule::exists('leave_types', 'id')
                ->where('organization_id', $request->user()->organization_id)],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        $days = $this->leave->workingDays(
            Carbon::parse($data['start_date']),
            Carbon::parse($data['end_date']),
        );

        if ($days < 1) {
            return back()->withErrors(['end_date' => 'Range contains no working days.']);
        }

        try {
            $updated = $this->leave->edit($leaveRequest, [
                'leave_type_id' => (int) $data['leave_type_id'],
                'start_date' => $data['start_date'],
                'end_date' => $data['end_date'],
                'days' => $days,
                'reason' => $data['reason'] ?? null,
            ]);
        } catch (\RuntimeException $e) {
            return back()->withErrors(['leave' => $e->getMessage()]);
        }

        // Consumers must resync a changed approved range.
        if ($updated->status === LeaveRequest::APPROVED) {
            app(WebhookService::class)->dispatch(
                WebhookEvent::LEAVE_APPROVED,
                (int) $leaveRequest->organization_id,
                ['leave_request_id' => $leaveRequest->id, 'employee_id' => $leaveRequest->employee_id],
            );
        }

        return redirect()
            ->route('leave.show', $leaveRequest)
            ->with('status', 'Leave request updated.');
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
        abort_unless($this->canReview($request, $leaveRequest), 403);
    }

    /**
     * leave.manage (admin, HR) and platform admins review anything; a
     * leave.approve-only user (manager) reviews their own team.
     */
    private function canReview(Request $request, LeaveRequest $leaveRequest): bool
    {
        $user = $request->user();

        if ($user->canPermission('leave.manage') || $user->is_platform_admin) {
            return true;
        }

        if (! $user->canPermission('leave.approve')) {
            return false;
        }

        return DB::table('employees')
            ->where('id', $leaveRequest->employee_id)
            ->where('manager_id', $user->id)
            ->exists();
    }
}
