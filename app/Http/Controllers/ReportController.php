<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\DailyAttendance;
use App\Models\Department;
use App\Models\Employee;
use App\Models\OvertimeRecord;
use App\Models\SalaryRecord;
use App\Services\ReportService;
use App\Services\RiskService;
use App\Services\ScoreService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends Controller
{
    public function __construct(
        private readonly ReportService $reports,
        private readonly ScoreService $scoring,
        private readonly RiskService $risk,
    ) {}

    public function attendance(Request $request): View
    {
        Gate::authorize('reports.view');

        $data = $this->attendanceData($this->filters($request, 'attendance'));

        return view('reports.attendance', $data);
    }

    public function monthly(Request $request): View
    {
        Gate::authorize('reports.view');

        $data = $this->monthlyData($this->filters($request, 'monthly'));

        return view('reports.monthly', $data);
    }

    public function salary(Request $request): View
    {
        Gate::authorize('reports.view');
        Gate::authorize('salary.view'); // pay data stays restricted (§41)

        $data = $this->salaryData($this->filters($request, 'salary'));

        return view('reports.salary', $data);
    }

    /**
     * Overtime report (§41 [OT]) — day rows with raw/approved minutes,
     * multiplier, pay (pay column only for salary.view), approver, status.
     */
    public function overtime(Request $request): View
    {
        Gate::authorize('reports.view');

        $data = $this->overtimeData($this->filters($request, 'overtime'));
        $data['showPay'] = Gate::allows('salary.view');

        return view('reports.overtime', $data);
    }

    /**
     * CSV export for any report type. Excel/PDF: open the CSV in Excel or
     * print the HTML view to PDF from the browser.
     */
    public function export(Request $request, string $type): StreamedResponse
    {
        Gate::authorize('reports.view');

        $filters = $this->filters($request, $type);

        [$filename, $headers, $rows] = match ($type) {
            'attendance' => $this->attendanceCsv($filters),
            'monthly' => $this->monthlyCsv($filters),
            'salary' => $this->salaryCsv($filters),
            'overtime' => $this->overtimeCsv($filters),
            default => abort(404),
        };

        return response()->streamDownload(function () use ($headers, $rows) {
            $out = fopen('php://output', 'w');
            fputcsv($out, $headers, ',', '"', '\\');
            foreach ($rows as $row) {
                fputcsv($out, $row, ',', '"', '\\');
            }
            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function filters(Request $request, string $type): array
    {
        $tz = $request->user()->organization?->timezone ?? 'UTC';

        $rules = match ($type) {
            'attendance' => [
                'from' => ['nullable', 'date'],
                'to' => ['nullable', 'date'],
                'employee_id' => ['nullable', 'integer'],
                'department_id' => ['nullable', 'integer'],
                'status' => ['nullable', Rule::in(['PRESENT', 'LATE', 'HALF_DAY', 'ABSENT', 'LEAVE', 'WEEKEND', 'HOLIDAY'])],
            ],
            'monthly' => [
                'month' => ['nullable', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
                'employee_id' => ['nullable', 'integer'],
                'department_id' => ['nullable', 'integer'],
            ],
            'salary' => [
                'month' => ['nullable', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
                'employee_id' => ['nullable', 'integer'],
                'department_id' => ['nullable', 'integer'],
            ],
            'overtime' => [
                'from' => ['nullable', 'date'],
                'to' => ['nullable', 'date'],
                'employee_id' => ['nullable', 'integer'],
                'department_id' => ['nullable', 'integer'],
                'day_type' => ['nullable', Rule::in(['WORKING_DAY', 'WEEKEND', 'HOLIDAY', 'LEAVE_DAY'])],
                'status' => ['nullable', Rule::in(['PENDING', 'APPROVED', 'REJECTED', 'FLAGGED'])],
            ],
            default => abort(404),
        };

        $data = $request->validate($rules, [
            'month.regex' => 'The month must be in YYYY-MM format.',
        ]);

        if (($data['from'] ?? null) && ($data['to'] ?? null) && $data['from'] > $data['to']) {
            throw ValidationException::withMessages(['to' => 'The end date must be on or after the start date.']);
        }

        $data['from'] = $data['from'] ?? Carbon::now($tz)->startOfMonth()->toDateString();
        $data['to'] = $data['to'] ?? Carbon::now($tz)->endOfMonth()->toDateString();
        $data['month'] = $data['month'] ?? Carbon::now($tz)->format('Y-m');

        return $data;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function attendanceData(array $filters): array
    {
        return [
            'type' => 'attendance',
            'filters' => $filters,
            'options' => $this->filterOptions(),
            'rows' => $this->attendanceQuery($filters)->get(),
        ];
    }

    /**
     * @return array{employees: Collection<int, Employee>, departments: Collection<int, Department>}
     */
    private function filterOptions(): array
    {
        return [
            'employees' => Employee::with('user:id,name')
                ->where('status', 'active')
                ->orderBy('employee_code')
                ->get(['id', 'employee_code', 'user_id']),
            'departments' => Department::orderBy('name')->get(['id', 'name']),
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<DailyAttendance>
     */
    private function attendanceQuery(array $filters)
    {
        $query = DailyAttendance::with(['employee.user:id,name', 'employee.department:id,name'])
            ->whereDate('date', '>=', $filters['from'])
            ->whereDate('date', '<=', $filters['to'])
            ->orderBy('date')
            ->orderBy('id');

        if (! empty($filters['employee_id'])) {
            $query->where('employee_id', (int) $filters['employee_id']);
        }
        if (! empty($filters['department_id'])) {
            $query->whereHas('employee', fn ($q) => $q->where('department_id', (int) $filters['department_id']));
        }
        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        return $query;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function monthlyData(array $filters): array
    {
        return [
            'type' => 'monthly',
            'filters' => $filters,
            'options' => $this->filterOptions(),
            'rows' => $this->monthlyRows($filters),
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    private function monthlyRows(array $filters): array
    {
        $organization = auth()->user()->organization;
        $organizationId = (int) auth()->user()->organization_id;
        $ledger = $this->reports->ledger($organizationId, $filters['month']);

        // One pass for the overtime split (§41 "approved / pending").
        $otByEmployee = [];
        OvertimeRecord::query()
            ->whereBetween('work_date', [
                $filters['month'].'-01',
                Carbon::createFromFormat('!Y-m-d', $filters['month'].'-01')->endOfMonth()->toDateString(),
            ])
            ->whereIn('status', [
                OvertimeRecord::STATUS_APPROVED,
                OvertimeRecord::STATUS_AUTO_APPROVED,
                OvertimeRecord::STATUS_PENDING,
            ])
            ->get(['employee_id', 'status', 'countable_minutes'])
            ->each(function (OvertimeRecord $record) use (&$otByEmployee) {
                $key = $record->employee_id;
                $otByEmployee[$key] ??= ['approved' => 0, 'pending' => 0];

                if ($record->status === OvertimeRecord::STATUS_PENDING) {
                    $otByEmployee[$key]['pending'] += $record->countable_minutes;
                } else {
                    $otByEmployee[$key]['approved'] += $record->countable_minutes;
                }
            });

        $employees = Employee::with(['user:id,name', 'department:id,name', 'organization'])
            ->where('status', 'active')
            ->when(! empty($filters['employee_id']), fn ($q) => $q->where('id', (int) $filters['employee_id']))
            ->when(! empty($filters['department_id']), fn ($q) => $q->where('department_id', (int) $filters['department_id']))
            ->orderBy('employee_code')
            ->get();

        $rows = [];
        foreach ($employees as $employee) {
            $totals = $this->reports->monthlyTotals($organizationId, $employee->id, $filters['month'], $ledger);
            $score = $this->scoring->compute($employee, $filters['month']);
            $ot = $otByEmployee[$employee->id] ?? ['approved' => 0, 'pending' => 0];

            $rows[] = [
                'name' => $employee->user?->name ?? '—',
                'code' => $employee->employee_code,
                'department' => $employee->department?->name ?? '—',
                'present' => $totals['present'],
                'absent' => $totals['absent'],
                'late' => $totals['late'],
                'leave' => $totals['leave'],
                'worked_hours' => round($totals['worked_minutes'] / 60, 1),
                'ot_approved_hours' => round($ot['approved'] / 60, 1),
                'ot_pending_hours' => round($ot['pending'] / 60, 1),
                'late_minutes' => $totals['late_minutes'],
                'scheduled_days' => $totals['scheduled_days'],
                'score' => $score ? round($score['score'], 2) : null,
                'status' => ($score && $organization)
                    ? $this->risk->statusFor($organization, (float) $score['score'])['label']
                    : '—',
            ];
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function salaryData(array $filters): array
    {
        return [
            'type' => 'salary',
            'filters' => $filters,
            'options' => $this->filterOptions(),
            'rows' => $this->salaryRows($filters),
        ];
    }

    /**
     * Effective salary record per employee for the month (§28 historical
     * correctness) — never the live estimate.
     *
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    private function salaryRows(array $filters): array
    {
        $organizationId = (int) auth()->user()->organization_id;
        $tz = auth()->user()->organization?->timezone ?? 'UTC';
        $month = $filters['month'];

        $start = Carbon::createFromFormat('!Y-m-d', $month.'-01');
        $end = $start->copy()->endOfMonth();
        $today = Carbon::now($tz);
        $cutoff = $today->toDateString() < $end->toDateString() ? $today->toDateString() : $end->toDateString();

        $employees = Employee::with(['user:id,name', 'department:id,name'])
            ->where('status', 'active')
            ->when(! empty($filters['employee_id']), fn ($q) => $q->where('id', (int) $filters['employee_id']))
            ->when(! empty($filters['department_id']), fn ($q) => $q->where('department_id', (int) $filters['department_id']))
            ->orderBy('employee_code')
            ->get();

        // Two batched effective-dating lookups (month start, then the
        // cutoff fallback) instead of up to two per employee.
        $byEmployee = SalaryRecord::effectiveForDate($organizationId, $start->toDateString());
        $cutoffByEmployee = $cutoff === $start->toDateString()
            ? $byEmployee
            : SalaryRecord::effectiveForDate($organizationId, $cutoff);

        $rows = [];
        foreach ($employees as $employee) {
            $record = $byEmployee[$employee->id] ?? $cutoffByEmployee[$employee->id] ?? null;

            $rows[] = [
                'name' => $employee->user?->name ?? '—',
                'code' => $employee->employee_code,
                'department' => $employee->department?->name ?? '—',
                'record' => $record,
                'basic' => $record?->basic_salary,
                'allowances' => $record?->allowances,
                'bonus' => $record?->bonus,
                'other_deductions' => $record?->deductions,
                'gross' => $record?->gross_salary,
                'net' => $record?->net_salary,
            ];
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{0: string, 1: list<string>, 2: list<list<string|int|float|null>>}
     */
    private function attendanceCsv(array $filters): array
    {
        $rows = [];
        foreach ($this->attendanceQuery($filters)->get() as $row) {
            $rows[] = [
                $row->date->toDateString(),
                $row->employee?->user?->name ?? '',
                $row->employee?->employee_code ?? '',
                $row->employee?->department?->name ?? '',
                $row->status,
                $row->first_check_in?->format('H:i') ?? '',
                $row->last_check_out?->format('H:i') ?? '',
                $row->total_work_minutes,
                $row->late_minutes,
                $row->overtime_minutes,
                $row->review_flag ?? '',
            ];
        }

        return [
            sprintf('attendance_%s_%s.csv', $filters['from'], $filters['to']),
            ['Date', 'Employee', 'Code', 'Department', 'Status', 'Check in', 'Check out', 'Worked minutes', 'Late minutes', 'Overtime minutes', 'Review flag'],
            $rows,
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{0: string, 1: list<string>, 2: list<list<string|int|float>>}
     */
    private function monthlyCsv(array $filters): array
    {
        $rows = [];
        foreach ($this->monthlyRows($filters) as $row) {
            $rows[] = [
                $row['name'],
                $row['code'],
                $row['department'],
                $row['scheduled_days'],
                $row['present'],
                $row['absent'],
                $row['late'],
                $row['leave'],
                $row['worked_hours'],
                $row['ot_approved_hours'],
                $row['ot_pending_hours'],
                $row['late_minutes'],
                $row['score'] ?? '',
                $row['status'],
            ];
        }

        return [
            sprintf('monthly_report_%s.csv', $filters['month']),
            ['Employee', 'Code', 'Department', 'Scheduled days', 'Present', 'Absent', 'Late', 'Leave', 'Working hours', 'Overtime approved hours', 'Overtime pending hours', 'Late minutes', 'Score', 'Status'],
            $rows,
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{0: string, 1: list<string>, 2: list<list<string|float|null>>}
     */
    private function salaryCsv(array $filters): array
    {
        Gate::authorize('salary.view');

        $rows = [];
        foreach ($this->salaryRows($filters) as $row) {
            $rows[] = [
                $row['name'],
                $row['code'],
                $row['department'],
                $row['basic'] ?? '',
                $row['allowances'] ?? '',
                $row['bonus'] ?? '',
                $row['other_deductions'] ?? '',
                $row['gross'] ?? '',
                $row['net'] ?? '',
            ];
        }

        return [
            sprintf('salary_report_%s.csv', $filters['month']),
            ['Employee', 'Code', 'Department', 'Basic', 'Allowances', 'Bonus', 'Other deductions', 'Gross', 'Net'],
            $rows,
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function overtimeData(array $filters): array
    {
        return [
            'type' => 'overtime',
            'filters' => $filters,
            'options' => $this->filterOptions(),
            'rows' => $this->overtimeQuery($filters)->get(),
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<OvertimeRecord>
     */
    private function overtimeQuery(array $filters)
    {
        $query = OvertimeRecord::with(['employee.user:id,name', 'employee.department:id,name', 'approvals.approver:id,name'])
            ->whereBetween('work_date', [$filters['from'], $filters['to']])
            ->orderBy('work_date')
            ->orderBy('id');

        if (! empty($filters['employee_id'])) {
            $query->where('employee_id', (int) $filters['employee_id']);
        }
        if (! empty($filters['department_id'])) {
            $query->whereHas('employee', fn ($q) => $q->where('department_id', (int) $filters['department_id']));
        }
        if (! empty($filters['day_type'])) {
            $query->where('day_type', $filters['day_type']);
        }
        if (! empty($filters['status'])) {
            // "Approved" covers both manual and automatic approvals (§41).
            if ($filters['status'] === 'APPROVED') {
                $query->whereIn('status', [OvertimeRecord::STATUS_APPROVED, OvertimeRecord::STATUS_AUTO_APPROVED]);
            } else {
                $query->where('status', $filters['status']);
            }
        }

        return $query;
    }

    /**
     * Pay is a restricted column (§41): only users with salary.view export it.
     *
     * @param  array<string, mixed>  $filters
     * @return array{0: string, 1: list<string>, 2: list<list<string|int|float>>}
     */
    private function overtimeCsv(array $filters): array
    {
        $showPay = Gate::allows('salary.view');

        $headers = ['Employee', 'Code', 'Department', 'Date', 'Day type', 'Raw minutes', 'Approved minutes', 'Multiplier'];
        if ($showPay) {
            $headers[] = 'Estimated pay';
        }
        $headers[] = 'Approver';
        $headers[] = 'Status';

        $rows = [];
        foreach ($this->overtimeQuery($filters)->get() as $record) {
            $approval = $record->approvals->whereNotNull('approver_user_id')->last();

            $row = [
                $record->employee?->user?->name ?? '',
                $record->employee?->employee_code ?? '',
                (string) (data_get($record, 'employee.department.name') ?? ''),
                $record->work_date->toDateString(),
                $record->day_type,
                $record->raw_minutes,
                $record->countable_minutes,
                (float) $record->multiplier,
            ];
            if ($showPay) {
                $row[] = (float) $record->estimated_pay;
            }
            $row[] = $approval?->approver?->name
                ?? ($record->status === OvertimeRecord::STATUS_AUTO_APPROVED ? 'Automatic' : '');
            $row[] = $record->status;
            $rows[] = $row;
        }

        return [
            sprintf('overtime_report_%s_%s.csv', $filters['from'], $filters['to']),
            $headers,
            $rows,
        ];
    }
}
