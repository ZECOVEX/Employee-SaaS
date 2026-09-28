<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\Payslip;
use App\Services\AuditLogger;
use App\Services\SalaryCalculator;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class PayslipController extends Controller
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly SalaryCalculator $calculator,
    ) {}

    /**
     * Finalize a monthly payroll record (§28): snapshot the current estimate
     * into an immutable payslip. Future periods are rejected; an active
     * payslip for the period must be revised instead of re-created.
     */
    public function store(Request $request, Employee $employee): RedirectResponse
    {
        Gate::authorize('salary.edit');

        $data = $request->validate([
            'period' => ['required', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            'notes' => ['nullable', 'string', 'max:500'],
        ], [
            'period.regex' => 'The period must be in YYYY-MM format.',
        ]);

        $period = $data['period'];
        $tz = $request->user()->organization?->timezone ?? 'UTC';
        $today = Carbon::now($tz);

        if (Carbon::createFromFormat('!Y-m-d', $period.'-01', $tz)->startOfMonth()->gt($today)) {
            throw ValidationException::withMessages([
                'period' => 'A future period cannot be finalized yet.',
            ]);
        }

        $activeExists = Payslip::withoutGlobalScopes()
            ->where('employee_id', $employee->id)
            ->where('period', $period)
            ->whereNull('superseded_by')
            ->exists();

        if ($activeExists) {
            throw ValidationException::withMessages([
                'period' => 'A payslip is already finalized for that period — use Revise instead.',
            ]);
        }

        $stats = $this->calculator->estimate($employee, $period);

        if ($stats === null) {
            throw ValidationException::withMessages([
                'period' => 'No salary record covers that period.',
            ]);
        }

        $payslip = DB::transaction(fn () => $this->createFromSnapshot(
            employee: $employee,
            stats: $stats,
            period: $period,
            revision: $this->nextRevision($employee->id, $period),
            notes: $data['notes'] ?? null,
            actorId: $request->user()->id,
        ));

        $this->audit->log('salary.payslip_finalized', $payslip, null, [
            'employee_id' => $employee->id,
            'period' => $period,
            'revision' => $payslip->revision,
            'gross_salary' => $payslip->gross_salary,
            'net_salary' => $payslip->net_salary,
        ]);

        return redirect()
            ->route('salary.show', $employee)
            ->with('status', 'Payslip finalized for '.$payslip->periodLabel().' — it will not change when attendance or rules change later.');
    }

    /**
     * Create a corrected revision of an active payslip. The superseded
     * revision is kept forever (revisions history, §28 / §52).
     */
    public function revise(Request $request, Employee $employee, Payslip $payslip): RedirectResponse
    {
        Gate::authorize('salary.edit');

        abort_unless($payslip->employee_id === $employee->id, 404);

        if ($payslip->isSuperseded()) {
            throw ValidationException::withMessages([
                'payslip' => 'That revision has already been superseded — revise the active one.',
            ]);
        }

        $data = $request->validate([
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $stats = $this->calculator->estimate($employee, $payslip->period);

        if ($stats === null) {
            throw ValidationException::withMessages([
                'payslip' => 'No salary record covers that period.',
            ]);
        }

        [$new, $old] = DB::transaction(function () use ($employee, $payslip, $stats, $data, $request) {
            $new = $this->createFromSnapshot(
                employee: $employee,
                stats: $stats,
                period: $payslip->period,
                revision: $this->nextRevision($employee->id, $payslip->period),
                notes: $data['notes'] ?? null,
                actorId: $request->user()->id,
            );

            $old = Payslip::withoutGlobalScopes()->findOrFail($payslip->id);
            $old->update(['superseded_by' => $new->id]);

            return [$new, $old];
        });

        $this->audit->log('salary.payslip_revised', $new, [
            'revision' => $old->revision,
            'net_salary' => $old->net_salary,
            'superseded_by' => $old->superseded_by,
        ], [
            'revision' => $new->revision,
            'net_salary' => $new->net_salary,
        ]);

        return redirect()
            ->route('salary.show', $employee)
            ->with('status', 'Revision '.$new->revision.' created for '.$new->periodLabel().' — the previous revision is kept as history.');
    }

    /**
     * One payslip with its full revision history. Same access rule as the
     * salary page: salary.view, or the employee themselves with view_own.
     */
    public function show(Request $request, Employee $employee, Payslip $payslip): View
    {
        $user = $request->user();
        $isSelf = $user->employee?->id === $employee->id;

        if (! Gate::allows('salary.view') && ! ($isSelf && Gate::allows('salary.view_own'))) {
            abort(403);
        }

        abort_unless($payslip->employee_id === $employee->id, 404);

        $revisions = Payslip::withoutGlobalScopes()
            ->where('employee_id', $employee->id)
            ->where('period', $payslip->period)
            ->with('creator:id,name')
            ->orderBy('revision')
            ->get();

        return view('salary.payslip', [
            'employee' => $employee,
            'payslip' => $payslip,
            'revisions' => $revisions,
            'canRevise' => Gate::allows('salary.edit') && ! $payslip->isSuperseded(),
        ]);
    }

    private function nextRevision(int $employeeId, string $period): int
    {
        $max = Payslip::withoutGlobalScopes()
            ->where('employee_id', $employeeId)
            ->where('period', $period)
            ->max('revision');

        return ((int) $max) + 1;
    }

    /**
     * @param  array<string, mixed>  $stats
     */
    private function createFromSnapshot(
        Employee $employee,
        array $stats,
        string $period,
        int $revision,
        ?string $notes,
        int $actorId,
    ): Payslip {
        $periodStart = Carbon::createFromFormat('!Y-m-d', $period.'-01');

        return Payslip::withoutGlobalScopes()->create([
            'organization_id' => $employee->organization_id,
            'employee_id' => $employee->id,
            'period' => $period,
            'period_start' => $periodStart->toDateString(),
            'period_end' => $periodStart->copy()->endOfMonth()->toDateString(),
            'revision' => $revision,
            'currency' => $stats['currency'],
            'basic_salary' => $stats['basic_salary'],
            'allowances' => $stats['allowances'],
            'bonus' => $stats['bonus'],
            'other_deductions' => $stats['record_deductions'],
            'gross_salary' => $stats['estimated_earnings'],
            'late_deduction' => $stats['late_deduction'],
            'absence_deduction' => $stats['absence_deduction'],
            'unpaid_leave_deduction' => $stats['unpaid_leave_deduction'],
            'attendance_deduction_total' => $stats['attendance_deduction_total'],
            'net_salary' => $stats['estimated_net'],
            'scheduled_days' => $stats['scheduled_days'],
            'completed_days' => $stats['completed_days'],
            'worked_minutes' => $stats['worked_minutes'],
            'late_minutes' => $stats['late_minutes'],
            'overtime_minutes' => $stats['overtime_minutes'],
            'daily_rate' => $stats['daily_rate'],
            'hourly_rate' => $stats['hourly_rate'],
            'capped' => $stats['capped'],
            'snapshot' => $stats,
            'notes' => $notes,
            'superseded_by' => null,
            'created_by' => $actorId,
        ]);
    }
}
