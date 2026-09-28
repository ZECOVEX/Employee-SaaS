<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\SalaryRecord;
use App\Notifications\SalaryUpdated;
use App\Services\AuditLogger;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class SalaryController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(Request $request): View
    {
        Gate::authorize('salary.view');

        $today = now($request->user()->organization?->timezone ?? 'UTC')->toDateString();

        $employees = Employee::with(['user:id,name', 'department:id,name'])
            ->where('status', 'active')
            ->orderBy('employee_code')
            ->get();

        $current = [];
        foreach ($employees as $employee) {
            $current[$employee->id] = SalaryRecord::effectiveFor(
                $request->user()->organization_id,
                $employee->id,
                $today,
            );
        }

        return view('salary.index', compact('employees', 'current'));
    }

    /**
     * Salary history for one employee. Viewable with salary.view, or by the
     * employee themselves with salary.view_own (self-service).
     */
    public function show(Request $request, Employee $employee): View
    {
        $user = $request->user();
        $isSelf = $user->employee?->id === $employee->id;

        if (! Gate::allows('salary.view') && ! ($isSelf && Gate::allows('salary.view_own'))) {
            abort(403);
        }

        $records = $employee->salaryRecords()
            ->with('creator:id,name')
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->get();

        $payslips = $employee->payslips()
            ->whereNull('superseded_by')
            ->with('creator:id,name')
            ->orderByDesc('period')
            ->orderByDesc('revision')
            ->get();

        $today = now($user->organization?->timezone ?? 'UTC')->toDateString();

        return view('salary.show', [
            'employee' => $employee,
            'records' => $records,
            'payslips' => $payslips,
            'current' => SalaryRecord::effectiveFor((int) $employee->organization_id, $employee->id, $today),
            'today' => $today,
            'canEdit' => Gate::allows('salary.edit'),
        ]);
    }

    /**
     * Add a new effective-dated salary version. Existing history is never
     * overwritten: an overlapping open range is closed the day before the
     * new one starts (§28).
     */
    public function store(Request $request, Employee $employee): RedirectResponse
    {
        Gate::authorize('salary.edit');

        $data = $request->validate([
            'basic_salary' => ['required', 'numeric', 'min:0', 'max:99999999.99'],
            'allowances' => ['nullable', 'numeric', 'min:0', 'max:99999999.99'],
            'bonus' => ['nullable', 'numeric', 'min:0', 'max:99999999.99'],
            'deductions' => ['nullable', 'numeric', 'min:0', 'max:99999999.99'],
            'effective_from' => ['required', 'date'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $from = Carbon::parse($data['effective_from'])->toDateString();

        $startsAlreadyTaken = SalaryRecord::withoutGlobalScopes()
            ->where('employee_id', $employee->id)
            ->where('effective_from', $from)
            ->exists();

        if ($startsAlreadyTaken) {
            throw ValidationException::withMessages([
                'effective_from' => 'A salary record already starts on that date — pick a different date.',
            ]);
        }

        $allowances = (float) ($data['allowances'] ?? 0);
        $bonus = (float) ($data['bonus'] ?? 0);
        $deductions = (float) ($data['deductions'] ?? 0);
        $amounts = SalaryRecord::computeAmounts((float) $data['basic_salary'], $allowances, $bonus, $deductions);
        $actorId = $request->user()->id;

        DB::transaction(function () use ($employee, $data, $from, $allowances, $bonus, $deductions, $amounts, $actorId) {
            // Close every earlier version that still covers the new start date.
            SalaryRecord::withoutGlobalScopes()
                ->where('employee_id', $employee->id)
                ->where('effective_from', '<', $from)
                ->where(function ($q) use ($from) {
                    $q->whereNull('effective_to')->orWhere('effective_to', '>=', $from);
                })
                ->update(['effective_to' => Carbon::parse($from)->subDay()->toDateString()]);

            SalaryRecord::withoutGlobalScopes()->create([
                'organization_id' => $employee->organization_id,
                'employee_id' => $employee->id,
                'basic_salary' => (float) $data['basic_salary'],
                'allowances' => $allowances,
                'bonus' => $bonus,
                'deductions' => $deductions,
                'gross_salary' => $amounts['gross_salary'],
                'net_salary' => $amounts['net_salary'],
                'effective_from' => $from,
                'effective_to' => null,
                'notes' => $data['notes'] ?? null,
                'created_by' => $actorId,
            ]);
        });

        $record = SalaryRecord::withoutGlobalScopes()
            ->where('employee_id', $employee->id)
            ->where('effective_from', $from)
            ->orderByDesc('id')
            ->first();

        $this->audit->log('salary.created', $record, null, [
            'employee_id' => $employee->id,
            'basic_salary' => (float) $data['basic_salary'],
            'allowances' => $allowances,
            'bonus' => $bonus,
            'deductions' => $deductions,
            'gross_salary' => $amounts['gross_salary'],
            'net_salary' => $amounts['net_salary'],
            'effective_from' => $from,
            'notes' => $data['notes'] ?? null,
        ]);

        $employee->user?->notify(new SalaryUpdated($from));

        return redirect()
            ->route('salary.show', $employee)
            ->with('status', 'Salary record added. Past reports keep their original figures.');
    }
}
