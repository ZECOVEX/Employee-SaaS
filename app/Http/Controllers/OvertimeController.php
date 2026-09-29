<?php

namespace App\Http\Controllers;

use App\Models\OvertimeRecord;
use App\Models\User;
use App\Services\OvertimeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * §73-I employee overtime visibility: the signed-in user always sees their
 * own records (pay only when the policy shows it). §73-E approval workflow:
 * HR/admin decide for the whole company, managers for their direct reports,
 * and nobody may decide their own overtime.
 */
class OvertimeController extends Controller
{
    public function __construct(private readonly OvertimeService $overtime) {}

    public function index(Request $request): View
    {
        $employee = $request->user()->employee;
        $organizationId = (int) $request->user()->organization_id;

        $records = OvertimeRecord::withoutGlobalScopes()
            ->when(
                $employee,
                fn ($query) => $query->where('employee_id', $employee->id),
                fn ($query) => $query->whereRaw('1 = 0'),
            )
            ->orderByDesc('work_date')
            ->paginate(20);

        $records->setCollection(
            $records->getCollection()->map(fn (OvertimeRecord $record) => [
                'record' => $record,
                'show_pay' => $this->overtime
                    ->policyFor($organizationId, $record->work_date->toDateString())
                    ->show_pay_to_employee,
            ]),
        );

        return view('overtime.index', [
            'records' => $records,
            'employee' => $employee,
            'statusLabels' => self::STATUS_LABELS,
            'dayLabels' => self::DAY_LABELS,
            'flagLabels' => self::FLAG_LABELS,
        ]);
    }

    /**
     * Pending records waiting for a decision (§73-E). Managers only see
     * their direct reports; HR and admins see the whole company.
     */
    public function queue(Request $request): View
    {
        $user = $request->user();

        $query = OvertimeRecord::withoutGlobalScopes()
            ->where('organization_id', $user->organization_id)
            ->where('status', OvertimeRecord::STATUS_PENDING)
            ->with('employee.user')
            ->orderByDesc('work_date')
            ->orderByDesc('id');

        $scoped = $user->canPermission('employees.view_team');

        if ($scoped) {
            $query->whereHas(
                'employee',
                fn ($q) => $q->withoutGlobalScopes()->where('manager_id', $user->id),
            );
        }

        return view('overtime.queue', [
            'records' => $query->paginate(20),
            'scoped' => $scoped,
        ]);
    }

    public function approve(Request $request, OvertimeRecord $overtimeRecord): RedirectResponse
    {
        $this->authorizeDecision($request->user(), $overtimeRecord);

        $this->overtime->approve($overtimeRecord, (int) $request->user()->id);

        return back()->with('status', 'Overtime approved.');
    }

    public function reject(Request $request, OvertimeRecord $overtimeRecord): RedirectResponse
    {
        $this->authorizeDecision($request->user(), $overtimeRecord);

        $data = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ]);

        $this->overtime->reject($overtimeRecord, (int) $request->user()->id, $data['reason']);

        return back()->with('status', 'Overtime rejected.');
    }

    public function adjust(Request $request, OvertimeRecord $overtimeRecord): RedirectResponse
    {
        $this->authorizeDecision($request->user(), $overtimeRecord);

        $data = $request->validate([
            'minutes' => ['required', 'integer', 'min:0', 'max:1440'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $this->overtime->adjust(
            $overtimeRecord,
            (int) $data['minutes'],
            (int) $request->user()->id,
            $data['reason'] ?? null,
        );

        return back()->with('status', 'Overtime adjusted.');
    }

    /**
     * Cross-org records already 404 through the organization scope; here we
     * block self-approval and manager out-of-team decisions (§73-E).
     */
    private function authorizeDecision(User $user, OvertimeRecord $record): void
    {
        abort_if($record->employee?->user_id === $user->id, 403, 'You cannot decide your own overtime.');

        if ($user->canPermission('employees.view_team')) {
            abort_unless($record->employee?->manager_id === $user->id, 403, 'You can only decide overtime for your direct reports.');
        }
    }

    private const STATUS_LABELS = [
        OvertimeRecord::STATUS_NONE => 'Not counted',
        OvertimeRecord::STATUS_PENDING => 'Pending approval',
        OvertimeRecord::STATUS_AUTO_APPROVED => 'Auto-approved',
        OvertimeRecord::STATUS_APPROVED => 'Approved',
        OvertimeRecord::STATUS_REJECTED => 'Rejected',
        OvertimeRecord::STATUS_FLAGGED => 'Needs review',
    ];

    private const DAY_LABELS = [
        OvertimeRecord::DAY_WORKING => 'Weekday',
        OvertimeRecord::DAY_WEEKEND => 'Weekend',
        OvertimeRecord::DAY_HOLIDAY => 'Holiday',
        OvertimeRecord::DAY_LEAVE => 'Leave day',
    ];

    private const FLAG_LABELS = [
        'possible_missed_checkin' => 'Missing check-out — correct your attendance first',
        'worked_during_leave' => 'Worked during leave — reviewed by HR',
        'pre_approval_required' => 'Pre-approval required',
        'excessive_segments' => 'Unusual punch pattern',
    ];
}
