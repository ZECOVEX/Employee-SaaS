<?php

namespace Tests\Feature;

use App\Models\AttendanceEvent;
use App\Models\DailyAttendance;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Organization;
use App\Models\OvertimePolicy;
use App\Models\OvertimeRecord;
use App\Models\SalaryRecord;
use App\Services\AttendanceService;
use App\Services\WorkforceDefaults;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesOrganizations;
use Tests\TestCase;

/**
 * Phase 5 M1 — overtime detection pipeline (§73-B..D, §73-H).
 *
 * Standard schedule (WorkforceDefaults): Mon–Fri 09:00–18:00, break
 * 13:00–14:00 → 480 expected minutes.
 */
class OvertimeDetectionTest extends TestCase
{
    use CreatesOrganizations;
    use RefreshDatabase;

    private int $currentOrgId = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-09-29 23:00:00');
    }

    public function test_above_expected_hours_counts_work_beyond_the_shift(): void
    {
        $employee = $this->employeeWithSchedule();

        // Spec §73-B example: arrive 08:30, break 13:00–14:00, leave 18:00
        // → 510 worked minutes − 480 expected = 30 minutes overtime.
        $daily = $this->seedDay($employee, '2026-09-29', [
            [AttendanceEvent::CHECK_IN, '08:30'],
            [AttendanceEvent::CHECK_OUT, '13:00'],
            [AttendanceEvent::CHECK_IN, '14:00'],
            [AttendanceEvent::CHECK_OUT, '18:00'],
        ]);

        $this->assertSame(510, $daily->total_work_minutes);

        $record = $this->recordFor($employee, '2026-09-29');
        $this->assertSame(OvertimeRecord::STATUS_AUTO_APPROVED, $record->status);
        $this->assertSame(OvertimeRecord::DAY_WORKING, $record->day_type);
        $this->assertSame(30, $record->countable_minutes);
        $this->assertEqualsWithDelta(1.5, $record->multiplier, 0.01);
        $this->assertSame(30, $daily->fresh()->overtime_minutes);
    }

    public function test_after_office_end_mode_only_counts_time_after_the_shift(): void
    {
        $employee = $this->employeeWithSchedule();
        $this->createPolicy(['mode' => OvertimePolicy::MODE_AFTER_END]);

        // Early arrival never creates overtime in AFTER_OFFICE_END mode.
        $this->seedDay($employee, '2026-09-25', [
            [AttendanceEvent::CHECK_IN, '08:30'],
            [AttendanceEvent::CHECK_OUT, '18:00'],
        ]);

        $this->assertNull($this->recordFor($employee, '2026-09-25'));
        $this->assertSame(0, DailyAttendance::withoutGlobalScopes()
            ->where('employee_id', $employee->id)
            ->where('date', '2026-09-25')
            ->first()
            ->overtime_minutes);

        // Spec §73-B: 09:00 → 19:15 = 75 minutes after office end.
        $this->seedDay($employee, '2026-09-28', [
            [AttendanceEvent::CHECK_IN, '09:00'],
            [AttendanceEvent::CHECK_OUT, '13:00'],
            [AttendanceEvent::CHECK_IN, '14:00'],
            [AttendanceEvent::CHECK_OUT, '19:15'],
        ]);

        $record = $this->recordFor($employee, '2026-09-28');
        $this->assertSame(75, $record->countable_minutes);
        $this->assertSame(OvertimeRecord::STATUS_AUTO_APPROVED, $record->status);
    }

    public function test_start_threshold_and_rounding_apply_before_capping(): void
    {
        $employee = $this->employeeWithSchedule();

        // 10 extra minutes < 15-minute threshold → nothing countable.
        $this->seedDay($employee, '2026-09-28', [
            [AttendanceEvent::CHECK_IN, '09:00'],
            [AttendanceEvent::CHECK_OUT, '13:00'],
            [AttendanceEvent::CHECK_IN, '14:00'],
            [AttendanceEvent::CHECK_OUT, '18:10'],
        ]);

        $below = $this->recordFor($employee, '2026-09-28');
        $this->assertSame(OvertimeRecord::STATUS_NONE, $below->status);
        $this->assertSame(10, $below->raw_minutes);
        $this->assertSame(0, $below->countable_minutes);

        // 20 extra minutes rounds to 15 (nearest 15-minute step).
        $this->seedDay($employee, '2026-09-29', [
            [AttendanceEvent::CHECK_IN, '09:00'],
            [AttendanceEvent::CHECK_OUT, '13:00'],
            [AttendanceEvent::CHECK_IN, '14:00'],
            [AttendanceEvent::CHECK_OUT, '18:20'],
        ]);

        $rounded = $this->recordFor($employee, '2026-09-29');
        $this->assertSame(15, $rounded->countable_minutes);
        $this->assertSame(OvertimeRecord::STATUS_AUTO_APPROVED, $rounded->status);
    }

    public function test_daily_cap_limits_countable_minutes(): void
    {
        $employee = $this->employeeWithSchedule();

        // 660 worked − 480 expected = 180 raw → default daily cap 120.
        $this->seedDay($employee, '2026-09-29', [
            [AttendanceEvent::CHECK_IN, '09:00'],
            [AttendanceEvent::CHECK_OUT, '13:00'],
            [AttendanceEvent::CHECK_IN, '14:00'],
            [AttendanceEvent::CHECK_OUT, '21:00'],
        ]);

        $record = $this->recordFor($employee, '2026-09-29');
        $this->assertSame(180, $record->raw_minutes);
        $this->assertSame(120, $record->countable_minutes);
        $this->assertSame(120, $this->dailyFor($employee, '2026-09-29')->overtime_minutes);
    }

    public function test_weekend_work_is_all_overtime_with_weekend_multiplier(): void
    {
        Carbon::setTestNow('2026-10-03 23:00:00'); // Saturday
        $employee = $this->employeeWithSchedule();

        $this->seedDay($employee, '2026-10-03', [
            [AttendanceEvent::CHECK_IN, '10:00'],
            [AttendanceEvent::CHECK_OUT, '13:00'],
            [AttendanceEvent::CHECK_IN, '14:00'],
            [AttendanceEvent::CHECK_OUT, '17:00'],
        ]);

        $record = $this->recordFor($employee, '2026-10-03');
        $this->assertSame(OvertimeRecord::DAY_WEEKEND, $record->day_type);
        $this->assertEqualsWithDelta(2.0, $record->multiplier, 0.01);
        $this->assertSame(360, $record->raw_minutes);
        $this->assertSame(120, $record->countable_minutes); // daily cap
    }

    public function test_weekend_all_overtime_off_falls_back_to_expected_hours(): void
    {
        Carbon::setTestNow('2026-10-03 23:00:00');
        $employee = $this->employeeWithSchedule();
        $this->createPolicy(['weekend_all_overtime' => false]);

        $this->seedDay($employee, '2026-10-03', [
            [AttendanceEvent::CHECK_IN, '10:00'],
            [AttendanceEvent::CHECK_OUT, '13:00'],
            [AttendanceEvent::CHECK_IN, '14:00'],
            [AttendanceEvent::CHECK_OUT, '17:00'],
        ]);

        // 360 worked < 480 expected → no overtime when the flag is off.
        $this->assertNull($this->recordFor($employee, '2026-10-03'));
    }

    public function test_flagged_days_never_produce_overtime(): void
    {
        $employee = $this->employeeWithSchedule();

        // Forgotten check-out on a past day (§72-C) → review flag → no OT.
        $daily = $this->seedDay($employee, '2026-09-28', [
            [AttendanceEvent::CHECK_IN, '09:00'],
        ]);

        $this->assertSame('possible_missed_checkin', $daily->review_flag);

        $record = $this->recordFor($employee, '2026-09-28');
        $this->assertSame(OvertimeRecord::STATUS_FLAGGED, $record->status);
        $this->assertSame('possible_missed_checkin', $record->flag_reason);
        $this->assertSame(0, $record->countable_minutes);
        $this->assertSame(0, $daily->fresh()->overtime_minutes);
    }

    public function test_working_while_on_leave_is_flagged_not_counted(): void
    {
        $employee = $this->employeeWithSchedule();
        $this->approvedLeaveOn($employee, '2026-09-29');

        $this->seedDay($employee, '2026-09-29', [
            [AttendanceEvent::CHECK_IN, '09:00'],
            [AttendanceEvent::CHECK_OUT, '13:00'],
            [AttendanceEvent::CHECK_IN, '14:00'],
            [AttendanceEvent::CHECK_OUT, '17:00'],
        ]);

        $record = $this->recordFor($employee, '2026-09-29');
        $this->assertSame(OvertimeRecord::DAY_LEAVE, $record->day_type);
        $this->assertSame(OvertimeRecord::STATUS_FLAGGED, $record->status);
        $this->assertSame('worked_during_leave', $record->flag_reason);
        $this->assertSame(0, $record->countable_minutes);
    }

    public function test_late_offset_counts_each_minute_once(): void
    {
        $employee = $this->employeeWithSchedule();
        $this->createPolicy(['offset_late_with_overtime' => true]);

        // 30 min late (09:30 vs 09:00+15 grace) + 45 raw overtime.
        $this->seedDay($employee, '2026-09-29', [
            [AttendanceEvent::CHECK_IN, '09:30'],
            [AttendanceEvent::CHECK_OUT, '13:00'],
            [AttendanceEvent::CHECK_IN, '14:00'],
            [AttendanceEvent::CHECK_OUT, '19:15'],
        ]);

        $daily = DailyAttendance::withoutGlobalScopes()
            ->where('employee_id', $employee->id)
            ->where('date', '2026-09-29')
            ->first();

        $this->assertSame(30, $daily->late_minutes);

        $record = $this->recordFor($employee, '2026-09-29');
        $this->assertSame(30, $record->late_offset_minutes);
        $this->assertSame(45, $record->raw_minutes);
        $this->assertSame(15, $record->countable_minutes); // 45 − 30 offset
    }

    public function test_manager_approval_mode_leaves_record_pending_and_mirrors_zero(): void
    {
        $employee = $this->employeeWithSchedule();
        $this->createPolicy(['approval_mode' => OvertimePolicy::APPROVE_MANAGER]);

        $daily = $this->seedDay($employee, '2026-09-29', [
            [AttendanceEvent::CHECK_IN, '09:00'],
            [AttendanceEvent::CHECK_OUT, '13:00'],
            [AttendanceEvent::CHECK_IN, '14:00'],
            [AttendanceEvent::CHECK_OUT, '19:15'],
        ]);

        $record = $this->recordFor($employee, '2026-09-29');
        $this->assertSame(OvertimeRecord::STATUS_PENDING, $record->status);
        $this->assertSame(75, $record->countable_minutes);
        $this->assertSame(0, $daily->fresh()->overtime_minutes);
    }

    public function test_ineligible_employee_gets_no_overtime(): void
    {
        $employee = $this->employeeWithSchedule();
        $employee->update(['overtime_eligibility' => Employee::OT_NOT_ELIGIBLE]);

        $daily = $this->seedDay($employee, '2026-09-29', [
            [AttendanceEvent::CHECK_IN, '09:00'],
            [AttendanceEvent::CHECK_OUT, '13:00'],
            [AttendanceEvent::CHECK_IN, '14:00'],
            [AttendanceEvent::CHECK_OUT, '19:15'],
        ]);

        $this->assertNull($this->recordFor($employee, '2026-09-29'));
        $this->assertSame(0, $daily->fresh()->overtime_minutes);
    }

    public function test_each_date_uses_the_policy_version_in_force_for_that_date(): void
    {
        $employee = $this->employeeWithSchedule();

        $old = $this->createPolicy(['mode' => OvertimePolicy::MODE_ABOVE_EXPECTED], [
            'effective_from' => '2026-09-01',
            'effective_to' => '2026-09-27',
        ]);
        $this->createPolicy(['enabled' => false], [
            'effective_from' => '2026-09-28',
            'effective_to' => null,
        ]);

        // Date under the old (enabled) version → overtime counts.
        $this->seedDay($employee, '2026-09-25', [
            [AttendanceEvent::CHECK_IN, '09:00'],
            [AttendanceEvent::CHECK_OUT, '13:00'],
            [AttendanceEvent::CHECK_IN, '14:00'],
            [AttendanceEvent::CHECK_OUT, '18:30'],
        ]);

        $record = $this->recordFor($employee, '2026-09-25');
        $this->assertNotNull($record);
        $this->assertSame($old->id, $record->policy_id);

        // Date under the new (disabled) version → no overtime.
        $this->seedDay($employee, '2026-09-29', [
            [AttendanceEvent::CHECK_IN, '09:00'],
            [AttendanceEvent::CHECK_OUT, '13:00'],
            [AttendanceEvent::CHECK_IN, '14:00'],
            [AttendanceEvent::CHECK_OUT, '18:30'],
        ]);

        $this->assertNull($this->recordFor($employee, '2026-09-29'));
    }

    public function test_weekly_cap_spreads_across_the_week_newest_day_first(): void
    {
        $employee = $this->employeeWithSchedule();
        $this->createPolicy([
            'weekly_cap_minutes' => 150,
            'daily_cap_minutes' => 720,
        ]);

        $times = [
            [AttendanceEvent::CHECK_IN, '09:00'],
            [AttendanceEvent::CHECK_OUT, '13:00'],
            [AttendanceEvent::CHECK_IN, '14:00'],
            [AttendanceEvent::CHECK_OUT, '19:30'],
        ];

        $this->seedDay($employee, '2026-09-28', $times); // Monday, 90 min
        $this->seedDay($employee, '2026-09-29', $times); // Tuesday, 90 min

        $monday = $this->recordFor($employee, '2026-09-28');
        $tuesday = $this->recordFor($employee, '2026-09-29');

        $this->assertSame(90, $monday->countable_minutes);
        $this->assertSame(60, $tuesday->countable_minutes); // newest day reduced
        $this->assertSame(90, $this->dailyFor($employee, '2026-09-28')->overtime_minutes);
        $this->assertSame(60, $this->dailyFor($employee, '2026-09-29')->overtime_minutes);
    }

    public function test_detection_notification_fires_once_per_day(): void
    {
        $employee = $this->employeeWithSchedule();

        $this->seedDay($employee, '2026-09-29', [
            [AttendanceEvent::CHECK_IN, '08:30'],
            [AttendanceEvent::CHECK_OUT, '13:00'],
            [AttendanceEvent::CHECK_IN, '14:00'],
            [AttendanceEvent::CHECK_OUT, '18:00'],
        ]);

        $this->assertSame(1, $employee->user->notifications()->count());

        // Re-derivation of the same day must not notify again.
        app(AttendanceService::class)->deriveDaily($employee, '2026-09-29');

        $this->assertSame(1, $employee->user->notifications()->count());
        $this->assertSame('Overtime detected', $employee->user->notifications()->first()->data['title']);
    }

    public function test_estimated_pay_snapshots_hourly_rate_and_multiplier(): void
    {
        $employee = $this->employeeWithSchedule();
        $this->salaryFor($employee);

        $this->seedDay($employee, '2026-09-29', [
            [AttendanceEvent::CHECK_IN, '09:00'],
            [AttendanceEvent::CHECK_OUT, '13:00'],
            [AttendanceEvent::CHECK_IN, '14:00'],
            [AttendanceEvent::CHECK_OUT, '19:00'],
        ]);

        $record = $this->recordFor($employee, '2026-09-29');
        $this->assertSame(60, $record->countable_minutes);
        // 48,000 ÷ 22 scheduled days ÷ 8 hours = 272.7273/hour.
        $this->assertEqualsWithDelta(272.7273, $record->hourly_rate_snapshot, 0.001);
        // 1h × 272.7273 × 1.5 (weekday) = 409.09.
        $this->assertEqualsWithDelta(409.09, $record->estimated_pay, 0.01);
    }

    public function test_policy_page_requires_settings_permission(): void
    {
        $ctx = $this->makeOrganization('OtPolicy');
        $hr = $this->makeUser($ctx['organization'], 'hr', $ctx['roles']);

        $this->actingAs($hr)
            ->get(route('overtime-policy.edit'))
            ->assertForbidden();

        $admin = $this->makeUser($ctx['organization'], 'company_admin', $ctx['roles']);

        $this->actingAs($admin)
            ->get(route('overtime-policy.edit'))
            ->assertOk()
            ->assertSee('Overtime Policy')
            ->assertSee('Approval mode');
    }

    public function test_policy_update_saves_versions_and_audits(): void
    {
        $ctx = $this->makeOrganization('OtPolicy2');
        $admin = $this->makeUser($ctx['organization'], 'company_admin', $ctx['roles']);

        $payload = [
            'enabled' => '1',
            'mode' => OvertimePolicy::MODE_AFTER_END,
            'start_threshold_minutes' => 15,
            'rounding_minutes' => 15,
            'rounding_method' => 'NEAREST',
            'daily_cap_minutes' => 120,
            'weekly_cap_minutes' => 720,
            'monthly_cap_minutes' => '',
            'max_shift_minutes' => 960,
            'approval_mode' => OvertimePolicy::APPROVE_MANAGER,
            'pending_expiry_days' => 7,
            'pending_expiry_action' => OvertimePolicy::EXPIRE_ESCALATE,
            'weekday_multiplier' => 1.5,
            'weekend_multiplier' => 2,
            'holiday_multiplier' => 2,
            'night_window_start' => '',
            'night_window_end' => '',
            'night_multiplier' => '',
            'rate_base' => OvertimePolicy::RATE_GROSS,
            'offset_late_with_overtime' => '0',
            'weekend_all_overtime' => '1',
            'require_pre_approval' => '0',
            'show_pay_to_employee' => '1',
            'show_hours_to_employee' => '1',
            'compensation_type' => OvertimePolicy::COMP_PAID,
            'overtime_score_bonus_max' => 0,
        ];

        $this->actingAs($admin)
            ->put(route('overtime-policy.update'), $payload)
            ->assertRedirect(route('overtime-policy.edit'));

        $first = OvertimePolicy::withoutGlobalScopes()
            ->where('organization_id', $ctx['organization']->id)
            ->firstOrFail();
        $this->assertSame(OvertimePolicy::MODE_AFTER_END, $first->mode);
        $this->assertSame(OvertimePolicy::APPROVE_MANAGER, $first->approval_mode);
        $this->assertNull($first->effective_from);

        // A second save versions the policy (effective dating, §73-G).
        $payload['mode'] = OvertimePolicy::MODE_ABOVE_EXPECTED;
        $this->actingAs($admin)
            ->put(route('overtime-policy.update'), $payload)
            ->assertRedirect(route('overtime-policy.edit'));

        $versions = OvertimePolicy::withoutGlobalScopes()
            ->where('organization_id', $ctx['organization']->id)
            ->orderBy('id')
            ->get();

        $this->assertCount(2, $versions);
        $this->assertSame(OvertimePolicy::MODE_AFTER_END, $versions[0]->mode);
        $this->assertSame('2026-09-28', $versions[0]->effective_to?->toDateString());
        $this->assertSame(OvertimePolicy::MODE_ABOVE_EXPECTED, $versions[1]->mode);
        $this->assertSame('2026-09-29', $versions[1]->effective_from?->toDateString());

        $this->assertDatabaseHas('audit_logs', [
            'organization_id' => $ctx['organization']->id,
            'action' => 'overtime_policy.updated',
        ]);
    }

    public function test_my_overtime_page_lists_own_records_and_respects_pay_visibility(): void
    {
        $employee = $this->employeeWithSchedule();
        $this->salaryFor($employee);

        $this->seedDay($employee, '2026-09-29', [
            [AttendanceEvent::CHECK_IN, '08:30'],
            [AttendanceEvent::CHECK_OUT, '13:00'],
            [AttendanceEvent::CHECK_IN, '14:00'],
            [AttendanceEvent::CHECK_OUT, '18:30'],
        ]);

        // 540 worked − 480 = 60 minutes; pay = 1h × 272.7273 × 1.5 = 409.09.
        $this->actingAs($employee->user)
            ->get(route('overtime.index'))
            ->assertOk()
            ->assertSee('My Overtime')
            ->assertSee('Tue, Sep 29, 2026')
            ->assertSee('Auto-approved')
            ->assertSee('409.09');

        // §73-I: show_pay_to_employee = false hides the amount.
        $this->createPolicy(['show_pay_to_employee' => false]);

        $this->actingAs($employee->user)
            ->get(route('overtime.index'))
            ->assertOk()
            ->assertSee('Hidden by policy')
            ->assertDontSee('409.09');

        // Another employee with a profile but no overtime sees an empty page.
        $organization = Organization::find($this->currentOrgId);
        $otherUser = $this->makeUser($organization, 'employee');
        $this->makeEmployee($organization, $otherUser, 'EMP-OT2');

        $this->actingAs($otherUser)
            ->get(route('overtime.index'))
            ->assertOk()
            ->assertSee('No overtime recorded yet.')
            ->assertDontSee('409.09');
    }

    private function employeeWithSchedule(): Employee
    {
        $ctx = $this->makeOrganization('OtCo '.uniqid());
        app(WorkforceDefaults::class)->apply($ctx['organization']);
        $user = $this->makeUser($ctx['organization'], 'employee', $ctx['roles']);
        $this->currentOrgId = $ctx['organization']->id;

        return $this->makeEmployee($ctx['organization'], $user, 'EMP-OT');
    }

    /**
     * Create attendance events directly and derive the day once (a single
     * derivation, exactly like a completed punch sequence).
     *
     * @param  array<int, array{0: string, 1: string}>  $times
     */
    private function seedDay(Employee $employee, string $date, array $times): DailyAttendance
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

        return app(AttendanceService::class)->deriveDaily($employee, $date);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>  $dates
     */
    private function createPolicy(array $attributes = [], array $dates = []): OvertimePolicy
    {
        return OvertimePolicy::withoutGlobalScopes()->create([
            'organization_id' => $this->currentOrgId,
            ...OvertimePolicy::defaults(),
            ...$attributes,
            ...$dates,
        ]);
    }

    private function recordFor(Employee $employee, string $date): ?OvertimeRecord
    {
        return OvertimeRecord::withoutGlobalScopes()
            ->where('employee_id', $employee->id)
            ->where('work_date', $date)
            ->first();
    }

    private function dailyFor(Employee $employee, string $date): DailyAttendance
    {
        return DailyAttendance::withoutGlobalScopes()
            ->where('employee_id', $employee->id)
            ->whereDate('date', $date)
            ->firstOrFail();
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

    private function salaryFor(Employee $employee): void
    {
        $amounts = SalaryRecord::computeAmounts(48000, 0, 0, 0);
        SalaryRecord::withoutGlobalScopes()->create([
            'organization_id' => $employee->organization_id,
            'employee_id' => $employee->id,
            'basic_salary' => 48000,
            'allowances' => 0,
            'bonus' => 0,
            'deductions' => 0,
            ...$amounts,
            'effective_from' => '2026-01-01',
        ]);
    }
}
