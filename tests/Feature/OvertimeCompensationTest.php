<?php

namespace Tests\Feature;

use App\Models\AttendanceEvent;
use App\Models\CompTimeEntry;
use App\Models\Employee;
use App\Models\Organization;
use App\Models\OvertimePolicy;
use App\Models\OvertimeRecord;
use App\Models\SalaryRecord;
use App\Models\User;
use App\Services\AttendanceService;
use App\Services\OvertimeService;
use App\Services\SalaryCalculator;
use App\Services\WorkforceDefaults;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesOrganizations;
use Tests\TestCase;

/**
 * Phase 5 M3 — §73-D/F: the late-offset exclusion in the salary estimate,
 * approved overtime pay as a separate line item, the COMP_TIME ledger, and
 * the §73-E expiry sweep (in OvertimeApprovalTest).
 *
 * Standard schedule (WorkforceDefaults): Mon–Fri 09:00–18:00, break
 * 13:00–14:00 → 480 expected minutes. Salary 48,000 → 272.7273/hour
 * (÷ 22 scheduled September days ÷ 8 hours).
 */
class OvertimeCompensationTest extends TestCase
{
    use CreatesOrganizations;
    use RefreshDatabase;

    private Organization $organization;

    /** @var array<string, mixed> */
    private array $ctx;

    private User $admin;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-09-29 23:00:00'); // Tuesday
    }

    public function test_late_offset_minutes_are_excluded_from_salary_deductions(): void
    {
        $employee = $this->employeeWithSalary();

        // In 10:00 (60 min late) → out 19:45: 525 worked = 45 raw overtime.
        $this->seedDay($employee, '2026-09-29', [
            [AttendanceEvent::CHECK_IN, '10:00'],
            [AttendanceEvent::CHECK_OUT, '13:00'],
            [AttendanceEvent::CHECK_IN, '14:00'],
            [AttendanceEvent::CHECK_OUT, '19:45'],
        ]);

        // Offset off (default): all 60 late minutes are deducted, grace 0.
        $stats = app(SalaryCalculator::class)->estimate($employee, '2026-09');
        $this->assertEqualsWithDelta(272.73, $stats['late_deduction'], 0.01);
        $this->assertSame(0, $stats['late_offset_minutes']);

        $byDate = array_column($stats['breakdown'], null, 'date');
        $this->assertSame(60, $byDate['2026-09-29']['late_minutes']);
        $this->assertSame(0, $byDate['2026-09-29']['late_offset_minutes']);

        // §73-D with offset on: min(60 late, 45 raw) = 45 absorbed by overtime,
        // only the 15-minute remainder is deducted (offset first, then grace).
        $this->createPolicy(['offset_late_with_overtime' => true]);
        app(AttendanceService::class)->deriveDaily($employee, '2026-09-29');

        $record = $this->recordFor($employee, '2026-09-29');
        $this->assertSame(45, $record->late_offset_minutes);
        $this->assertSame(0, $record->countable_minutes); // 45 − 45 offset

        $stats = app(SalaryCalculator::class)->estimate($employee, '2026-09');
        $this->assertEqualsWithDelta(68.18, $stats['late_deduction'], 0.01); // 15/60 × 272.73
        $this->assertSame(45, $stats['late_offset_minutes']);

        $byDate = array_column($stats['breakdown'], null, 'date');
        $this->assertSame(60, $byDate['2026-09-29']['late_minutes']);
        $this->assertSame(45, $byDate['2026-09-29']['late_offset_minutes']);
    }

    public function test_approved_overtime_pay_is_included_and_pending_is_potential_only(): void
    {
        $employee = $this->employeeWithSalary();
        $this->createPolicy(['approval_mode' => OvertimePolicy::APPROVE_MANAGER]);

        // Monday 90 min + Tuesday 75 min, both pending a decision.
        $this->seedDay($employee, '2026-09-28', [
            [AttendanceEvent::CHECK_IN, '09:00'],
            [AttendanceEvent::CHECK_OUT, '13:00'],
            [AttendanceEvent::CHECK_IN, '14:00'],
            [AttendanceEvent::CHECK_OUT, '19:30'],
        ]);
        $tuesday = $this->seedDay($employee, '2026-09-29', [
            [AttendanceEvent::CHECK_IN, '09:00'],
            [AttendanceEvent::CHECK_OUT, '13:00'],
            [AttendanceEvent::CHECK_IN, '14:00'],
            [AttendanceEvent::CHECK_OUT, '19:15'],
        ]);

        $pre = app(SalaryCalculator::class)->estimate($employee, '2026-09');
        $this->assertInstanceOf(SalaryRecord::class, $pre['record']); // never clobbered by OT rows
        $this->assertEqualsWithDelta(0.0, $pre['overtime_pay'], 0.001);
        // 613.64 (90 min) + 511.36 (75 min) — all potential while pending.
        $this->assertEqualsWithDelta(1125.00, $pre['potential_overtime_pay'], 0.02);

        app(OvertimeService::class)->approve(
            $this->recordFor($employee, '2026-09-29'),
            $this->admin->id,
        );

        $stats = app(SalaryCalculator::class)->estimate($employee, '2026-09');
        $this->assertEqualsWithDelta(511.36, $stats['overtime_pay'], 0.01);
        $this->assertEqualsWithDelta(613.64, $stats['potential_overtime_pay'], 0.01);

        // Approved pay enters earnings and net; pending stays excluded and
        // the attendance deductions are untouched.
        $this->assertEqualsWithDelta($pre['estimated_earnings'] + 511.36, $stats['estimated_earnings'], 0.02);
        $this->assertEqualsWithDelta($pre['estimated_net'] + 511.36, $stats['estimated_net'], 0.02);
        $this->assertEqualsWithDelta(
            $pre['attendance_deduction_total'],
            $stats['attendance_deduction_total'],
            0.001,
        );

        $this->assertSame(75, $tuesday->fresh()->countable_minutes);
    }

    public function test_comp_time_accrues_a_ledger_row_when_auto_approved(): void
    {
        $employee = $this->employeeWithSalary();
        $this->createPolicy(['compensation_type' => OvertimePolicy::COMP_TIME]);

        $record = $this->seedDay($employee, '2026-09-29', [
            [AttendanceEvent::CHECK_IN, '09:00'],
            [AttendanceEvent::CHECK_OUT, '13:00'],
            [AttendanceEvent::CHECK_IN, '14:00'],
            [AttendanceEvent::CHECK_OUT, '19:00'],
        ]);

        $this->assertSame(OvertimeRecord::STATUS_AUTO_APPROVED, $record->status);
        $this->assertSame(60, $record->countable_minutes);
        $this->assertEqualsWithDelta(0.0, $record->estimated_pay, 0.001); // comp, not pay

        $entry = CompTimeEntry::withoutGlobalScopes()
            ->where('employee_id', $employee->id)
            ->firstOrFail();
        $this->assertSame(60, $entry->minutes);
        $this->assertSame('2026-09-29', $entry->work_date->toDateString());
        $this->assertSame(
            $record->id,
            $entry->overtime_record_id,
        );

        // An audited zeroing clears the accrual (idempotent by design).
        app(OvertimeService::class)->adjust($record, 0, $this->admin->id, 'Not worked after all');
        $this->assertSame(0, CompTimeEntry::withoutGlobalScopes()
            ->where('employee_id', $employee->id)
            ->count());
    }

    public function test_paid_overtime_never_touches_the_comp_ledger(): void
    {
        $employee = $this->employeeWithSalary();

        // Default policy pays overtime — no ledger rows ever appear.
        $this->seedDay($employee, '2026-09-29', [
            [AttendanceEvent::CHECK_IN, '09:00'],
            [AttendanceEvent::CHECK_OUT, '13:00'],
            [AttendanceEvent::CHECK_IN, '14:00'],
            [AttendanceEvent::CHECK_OUT, '19:00'],
        ]);
        $this->seedDay($employee, '2026-09-28', [
            [AttendanceEvent::CHECK_IN, '09:00'],
            [AttendanceEvent::CHECK_OUT, '13:00'],
            [AttendanceEvent::CHECK_IN, '14:00'],
            [AttendanceEvent::CHECK_OUT, '19:30'],
        ]);

        $this->assertSame(0, CompTimeEntry::withoutGlobalScopes()
            ->where('employee_id', $employee->id)
            ->count());
    }

    public function test_decisions_keep_the_comp_ledger_in_sync(): void
    {
        $employee = $this->employeeWithSalary();
        $this->createPolicy([
            'compensation_type' => OvertimePolicy::COMP_TIME,
            'approval_mode' => OvertimePolicy::APPROVE_MANAGER,
        ]);
        $service = app(OvertimeService::class);

        $pending = $this->seedDay($employee, '2026-09-29', [
            [AttendanceEvent::CHECK_IN, '09:00'],
            [AttendanceEvent::CHECK_OUT, '13:00'],
            [AttendanceEvent::CHECK_IN, '14:00'],
            [AttendanceEvent::CHECK_OUT, '19:15'],
        ]);
        $this->assertSame(OvertimeRecord::STATUS_PENDING, $pending->status);
        $this->assertSame(0, $this->compCount($employee)); // pending never accrues

        $service->approve($pending, $this->admin->id);
        $this->assertSame(75, $this->compMinutes($employee));

        // A rejection never accrues, even for an old pending record.
        $rejected = $this->seedDay($employee, '2026-09-28', [
            [AttendanceEvent::CHECK_IN, '09:00'],
            [AttendanceEvent::CHECK_OUT, '13:00'],
            [AttendanceEvent::CHECK_IN, '14:00'],
            [AttendanceEvent::CHECK_OUT, '19:30'],
        ]);
        $service->reject($rejected, $this->admin->id, 'Not agreed');
        $this->assertSame(1, $this->compCount($employee)); // still only the approved row
        $this->assertSame(75, $this->compMinutes($employee));

        // Adjusting the approved record updates the same ledger row.
        $service->adjust($pending, 30, $this->admin->id, 'Corrected');
        $this->assertSame(30, $this->compMinutes($employee));
        $this->assertSame(1, $this->compCount($employee));
    }

    public function test_comp_balance_shows_on_the_my_overtime_page(): void
    {
        $employee = $this->employeeWithSalary();
        $this->createPolicy(['compensation_type' => OvertimePolicy::COMP_TIME]);

        $this->seedDay($employee, '2026-09-29', [
            [AttendanceEvent::CHECK_IN, '09:00'],
            [AttendanceEvent::CHECK_OUT, '13:00'],
            [AttendanceEvent::CHECK_IN, '14:00'],
            [AttendanceEvent::CHECK_OUT, '19:00'],
        ]);

        $this->actingAs($employee->user)
            ->get(route('overtime.index'))
            ->assertOk()
            ->assertSee('Comp time balance')
            ->assertSee('1h');

        // A colleague without accruals never sees the card.
        $otherUser = $this->makeUser($this->organization, 'employee2', $this->ctx['roles']);
        $this->makeEmployee($this->organization, $otherUser, 'EMP-COMP2');

        $this->actingAs($otherUser)
            ->get(route('overtime.index'))
            ->assertOk()
            ->assertDontSee('Comp time balance');
    }

    private function employeeWithSalary(): Employee
    {
        $this->ctx = $this->makeOrganization('OtPay '.uniqid());
        $this->organization = $this->ctx['organization'];
        app(WorkforceDefaults::class)->apply($this->organization);

        $this->admin = $this->makeUser($this->organization, 'company_admin', $this->ctx['roles']);
        $user = $this->makeUser($this->organization, 'employee', $this->ctx['roles']);
        $this->employee = $this->makeEmployee($this->organization, $user, 'EMP-COMP');

        $amounts = SalaryRecord::computeAmounts(48000, 0, 0, 0);
        SalaryRecord::withoutGlobalScopes()->create([
            'organization_id' => $this->organization->id,
            'employee_id' => $this->employee->id,
            'basic_salary' => 48000,
            'allowances' => 0,
            'bonus' => 0,
            'deductions' => 0,
            ...$amounts,
            'effective_from' => '2026-01-01',
        ]);

        return $this->employee;
    }

    /**
     * Create attendance events directly and derive the day once (a single
     * derivation, exactly like a completed punch sequence).
     *
     * @param  array<int, array{0: string, 1: string}>  $times
     */
    private function seedDay(Employee $employee, string $date, array $times): OvertimeRecord
    {
        foreach ($times as [$type, $time]) {
            AttendanceEvent::withoutGlobalScopes()->create([
                'organization_id' => $employee->organization_id,
                'employee_id' => $employee->id,
                'event_type' => $type,
                'occurred_at' => Carbon::parse($date.' '.$time),
                'timezone' => 'UTC',
                'source' => 'manual',
                'notes' => 'seed',
                'created_by' => $employee->user_id,
            ]);
        }

        app(AttendanceService::class)->deriveDaily($employee, $date);

        return $this->recordFor($employee, $date);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createPolicy(array $attributes = []): OvertimePolicy
    {
        return OvertimePolicy::withoutGlobalScopes()->create([
            'organization_id' => $this->organization->id,
            ...OvertimePolicy::defaults(),
            ...$attributes,
        ]);
    }

    private function recordFor(Employee $employee, string $date): OvertimeRecord
    {
        return OvertimeRecord::withoutGlobalScopes()
            ->where('employee_id', $employee->id)
            ->where('work_date', $date)
            ->firstOrFail();
    }

    private function compCount(Employee $employee): int
    {
        return CompTimeEntry::withoutGlobalScopes()
            ->where('employee_id', $employee->id)
            ->count();
    }

    private function compMinutes(Employee $employee): int
    {
        return (int) CompTimeEntry::withoutGlobalScopes()
            ->where('employee_id', $employee->id)
            ->sum('minutes');
    }
}
