<?php

namespace Tests\Feature;

use App\Models\AttendanceEvent;
use App\Models\DailyAttendance;
use App\Models\Employee;
use App\Models\EmployeeMonthlyStatus;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Organization;
use App\Models\OvertimeRecord;
use App\Models\Role;
use App\Services\AttendanceService;
use App\Services\MonthlyStatusService;
use App\Services\WorkforceDefaults;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesOrganizations;
use Tests\TestCase;

/**
 * Phase 5 M5c — §17 employee_monthly_status: finalized snapshots with the
 * score components, day counts, worked/expected minutes and overtime
 * totals; generated once (month-end or on demand) and never rewritten.
 */
class MonthlyStatusTest extends TestCase
{
    use CreatesOrganizations;
    use RefreshDatabase;

    private Organization $organization;

    /** @var array<string, Role> */
    private array $roles;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-09-30 23:00:00');

        $ctx = $this->makeOrganization('Monthly '.uniqid());
        $this->organization = $ctx['organization'];
        $this->roles = $ctx['roles'];
        app(WorkforceDefaults::class)->apply($this->organization);
    }

    public function test_snapshot_captures_score_components_days_and_overtime(): void
    {
        $employee = $this->employee();

        // 18 clean days, Sep 8+9 late, Sep 10 absent, Sep 11 on leave.
        $overrides = [
            '2026-09-08' => ['09:30', '18:00'],
            '2026-09-09' => ['09:30', '18:00'],
            '2026-09-10' => null,
            '2026-09-11' => null,
        ];

        foreach ($this->septemberWeekdays() as $date) {
            if (array_key_exists($date, $overrides)) {
                if ($overrides[$date] !== null) {
                    $this->seedDay($employee, $date, $overrides[$date][0], $overrides[$date][1]);
                }

                continue;
            }

            $this->seedDay($employee, $date, '09:00', '18:00');
        }

        $this->approvedLeaveOn($employee, '2026-09-11');

        // Overtime on weekend dates (no daily records exist there): one of
        // each decision state so every total column is exercised.
        $this->overtimeRecord($employee, '2026-09-05', OvertimeRecord::STATUS_APPROVED, 60);
        $this->overtimeRecord($employee, '2026-09-06', OvertimeRecord::STATUS_PENDING, 30);
        $this->overtimeRecord($employee, '2026-09-12', OvertimeRecord::STATUS_REJECTED, 60);

        $this->assertSame(1, app(MonthlyStatusService::class)->generate('2026-09'));

        $row = EmployeeMonthlyStatus::withoutGlobalScopes()->sole();

        $this->assertSame('2026-09', $row->periodKey());
        $this->assertSame(EmployeeMonthlyStatus::STATUS_FINALIZED, $row->status);
        $this->assertNotNull($row->generated_at);

        $this->assertEqualsWithDelta(87.15, $row->attendance_score, 0.01);
        $this->assertEqualsWithDelta(95.24, $row->attendance_rate, 0.01);
        $this->assertEqualsWithDelta(90.48, $row->punctuality_score, 0.01);
        $this->assertEqualsWithDelta(94.64, $row->working_hours_score, 0.01);
        $this->assertEqualsWithDelta(50.00, $row->leave_score, 0.01);

        $this->assertSame(20, $row->days_present);
        $this->assertSame(2, $row->days_late);
        $this->assertSame(1, $row->days_absent);
        $this->assertSame(1, $row->days_on_leave);
        $this->assertSame(9540, $row->total_work_minutes);
        $this->assertSame(10080, $row->expected_work_minutes);

        $this->assertSame(60, $row->overtime_minutes_approved);
        $this->assertSame(30, $row->overtime_minutes_pending);
        $this->assertSame(60, $row->overtime_minutes_rejected);
        $this->assertSame(3, $row->overtime_days);
    }

    public function test_generation_is_idempotent_and_never_changes_a_finalized_row(): void
    {
        $employee = $this->employee();

        foreach ($this->septemberWeekdays() as $date) {
            $this->seedDay($employee, $date, '09:00', '18:00');
        }

        $service = app(MonthlyStatusService::class);

        $this->assertSame(1, $service->generate('2026-09'));

        $row = EmployeeMonthlyStatus::withoutGlobalScopes()->sole();
        $this->assertEqualsWithDelta(100.0, $row->attendance_score, 0.01);
        $this->assertSame(0, $row->days_late);
        $generatedAt = $row->generated_at->toDateTimeString();

        // Attendance changes after finalization must not touch the row.
        DailyAttendance::withoutGlobalScopes()
            ->where('employee_id', $employee->id)
            ->whereDate('date', '2026-09-15')
            ->update(['status' => 'LATE', 'late_minutes' => 30]);

        $this->assertSame(0, $service->generate('2026-09'));

        $row->refresh();
        $this->assertEqualsWithDelta(100.0, $row->attendance_score, 0.01);
        $this->assertSame(0, $row->days_late);
        $this->assertSame($generatedAt, $row->generated_at->toDateTimeString());
        $this->assertSame(1, EmployeeMonthlyStatus::withoutGlobalScopes()->count());
    }

    public function test_future_and_invalid_months_are_rejected(): void
    {
        $this->employee();

        $service = app(MonthlyStatusService::class);

        // October has no elapsed day yet at 2026-09-30.
        $this->assertSame(0, $service->generate('2026-10'));
        $this->assertSame(0, EmployeeMonthlyStatus::withoutGlobalScopes()->count());

        $this->expectException(\InvalidArgumentException::class);
        $service->generate('2026-13');
    }

    public function test_command_generates_for_the_requested_month(): void
    {
        $this->employee();

        $this->artisan('status:generate', ['--month' => '2026-09'])
            ->assertSuccessful()
            ->expectsOutputToContain('Generated 1 monthly status records for 2026-09.');

        $this->assertSame('2026-09', EmployeeMonthlyStatus::withoutGlobalScopes()->sole()->periodKey());

        $this->artisan('status:generate', ['--month' => 'september'])
            ->assertFailed();
    }

    private function employee(): Employee
    {
        $user = $this->makeUser($this->organization, 'employee', $this->roles);

        return $this->makeEmployee($this->organization, $user, 'EMP-STATUS');
    }

    /** @return list<string> */
    private function septemberWeekdays(): array
    {
        $dates = [];
        $cursor = Carbon::parse('2026-09-01');

        while ($cursor->month === 9) {
            if (in_array((int) $cursor->dayOfWeekIso, [1, 2, 3, 4, 5], true)) {
                $dates[] = $cursor->toDateString();
            }
            $cursor->addDay();
        }

        return $dates;
    }

    private function seedDay(Employee $employee, string $date, string $in, string $out): DailyAttendance
    {
        foreach ([[AttendanceEvent::CHECK_IN, $in], [AttendanceEvent::CHECK_OUT, $out]] as [$type, $time]) {
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

        return app(AttendanceService::class)->deriveDaily($employee, $date);
    }

    private function overtimeRecord(Employee $employee, string $date, string $status, int $minutes): void
    {
        OvertimeRecord::withoutGlobalScopes()->create([
            'organization_id' => $employee->organization_id,
            'employee_id' => $employee->id,
            'work_date' => $date,
            'day_type' => in_array($status, [OvertimeRecord::STATUS_APPROVED, OvertimeRecord::STATUS_PENDING, OvertimeRecord::STATUS_REJECTED], true)
                ? OvertimeRecord::DAY_WEEKEND : OvertimeRecord::DAY_WORKING,
            'worked_minutes' => $minutes + 480,
            'expected_minutes' => 480,
            'raw_minutes' => $minutes,
            'late_offset_minutes' => 0,
            'countable_minutes' => $minutes,
            'multiplier' => 2.0,
            'hourly_rate_snapshot' => 0,
            'estimated_pay' => 0,
            'status' => $status,
            'calculated_at' => now(),
        ]);
    }

    private function approvedLeaveOn(Employee $employee, string $date): void
    {
        $type = LeaveType::withoutGlobalScopes()
            ->where('organization_id', $employee->organization_id)
            ->where('code', 'ANNUAL')
            ->firstOrFail();

        $request = new LeaveRequest([
            'start_date' => $date,
            'end_date' => $date,
            'days' => 1,
            'reason' => 'Trip',
            'status' => LeaveRequest::APPROVED,
        ]);
        $request->organization()->associate($employee->organization_id);
        $request->employee()->associate($employee);
        $request->leaveType()->associate($type);
        $request->save();
    }
}
