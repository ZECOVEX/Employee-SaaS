<?php

namespace Tests\Feature;

use App\Models\AttendanceEvent;
use App\Models\DailyAttendance;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\OvertimePolicy;
use App\Services\AttendanceService;
use App\Services\ScoreService;
use App\Services\WorkforceDefaults;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesOrganizations;
use Tests\TestCase;

/**
 * Phase 5 M4 — attendance score engine (§16): the weighted 40/25/20/15
 * formula, the visible breakdown, overtime's cap rules ([OT]: working hours
 * capped at 100 with overtime excluded, bonus capped and shown as its own
 * line) and approved leave staying neutral.
 *
 * September 2026: 22 Mon–Fri days. WorkforceDefaults: 09:00–18:00, break
 * 13:00–14:00 → 480 expected minutes, 15-minute grace.
 */
class AttendanceScoreTest extends TestCase
{
    use CreatesOrganizations;
    use RefreshDatabase;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-09-30 23:00:00'); // end of the scored month
    }

    public function test_score_components_follow_the_weighted_formula(): void
    {
        $employee = $this->employeeWithDefaults();

        // 18 days on time, Sep 8+9 late (09:30), Sep 10 absent, Sep 11 on
        // approved leave → 20 attended of 21 attendable days.
        $this->seedMonth($employee, [
            '2026-09-08' => ['09:30', '18:00'],
            '2026-09-09' => ['09:30', '18:00'],
            '2026-09-10' => null, // absent
            '2026-09-11' => null, // leave instead (approved below)
        ]);
        $this->approvedLeaveOn($employee, '2026-09-11');

        $score = app(ScoreService::class)->compute($employee, '2026-09');

        $this->assertNotNull($score);
        // attended 20 / attendable 21; late 2; worked 18×480 + 2×450 = 9540
        // of 21×480 = 10080 expected; leave 1, absent 1.
        $this->assertEqualsWithDelta(95.24, $score['scores']['attendance_rate'], 0.01);
        $this->assertEqualsWithDelta(90.48, $score['scores']['punctuality'], 0.01);
        $this->assertEqualsWithDelta(94.64, $score['scores']['working_hours'], 0.01);
        $this->assertEqualsWithDelta(50.00, $score['scores']['leave_compliance'], 0.01);

        // Displayed × displayed = displayed: the breakdown reconciles.
        $weighted = array_column($score['components'], 'weighted');
        $this->assertEqualsWithDelta(38.10, $weighted[0], 0.001);
        $this->assertEqualsWithDelta(22.62, $weighted[1], 0.001);
        $this->assertEqualsWithDelta(18.93, $weighted[2], 0.001);
        $this->assertEqualsWithDelta(7.50, $weighted[3], 0.001);
        $this->assertEqualsWithDelta(87.15, $score['base_score'], 0.01);
        $this->assertEqualsWithDelta(87.15, $score['score'], 0.01);

        $this->assertSame(0.0, $score['overtime_bonus']); // default: none
        $this->assertSame(
            'Attendance Rate × 40% + Punctuality × 25% + Working Hours × 20% + Leave Compliance × 15%',
            $score['formula'],
        );
        $this->assertSame(21, $score['inputs']['attendable']);
        $this->assertSame(2, $score['inputs']['late']); // Sep 8 and Sep 9
        $this->assertSame(1, $score['inputs']['absent']);
        $this->assertSame(1, $score['inputs']['leave']);
    }

    public function test_overtime_never_inflates_or_lowers_the_score(): void
    {
        $plain = $this->employeeWithDefaults();
        $overtime = $this->employeeWithDefaults();

        // Twin months: identical attendance, but one checks out at 20:00
        // (600 worked = 480 regular + 120 overtime every day).
        $this->seedMonth($plain);
        $this->seedMonth($overtime, [], '09:00', '20:00');

        $plainScore = app(ScoreService::class)->compute($plain, '2026-09');
        $otScore = app(ScoreService::class)->compute($overtime, '2026-09');

        // Lots of approved overtime exists…
        $this->assertGreaterThan(0, $otScore['approved_overtime_minutes']);
        // …but Working Hours is capped at 100 with overtime excluded, and
        // the score is identical: extra hours cannot mask, and never lower.
        $this->assertEqualsWithDelta(100.0, $plainScore['scores']['working_hours'], 0.001);
        $this->assertEqualsWithDelta(100.0, $otScore['scores']['working_hours'], 0.001);
        $this->assertEqualsWithDelta($plainScore['score'], $otScore['score'], 0.001);
        $this->assertSame(0.0, $otScore['overtime_bonus']); // bonus default none
    }

    public function test_overtime_bonus_is_capped_and_shown_as_its_own_line(): void
    {
        $employee = $this->employeeWithDefaults();
        $this->createPolicy(['overtime_score_bonus_max' => 1]);

        // 22 days × 120 approved overtime minutes = 2640 → raw bonus 11.0,
        // capped at the policy maximum of +1.
        $this->seedMonth($employee, [], '09:00', '20:00');

        $score = app(ScoreService::class)->compute($employee, '2026-09');

        $this->assertSame(2640, $score['approved_overtime_minutes']);
        $this->assertEqualsWithDelta(1.0, $score['overtime_bonus'], 0.001);
        $this->assertEqualsWithDelta(1.0, $score['overtime_bonus_max'], 0.001);
        // Perfect attendance components = 100 base + 1 capped bonus.
        $this->assertEqualsWithDelta(100.0, $score['base_score'], 0.01);
        $this->assertEqualsWithDelta(101.0, $score['score'], 0.01);
    }

    public function test_approved_leave_never_lowers_the_score(): void
    {
        $employee = $this->employeeWithDefaults();

        $this->seedMonth($employee, ['2026-09-11' => null]);
        $this->approvedLeaveOn($employee, '2026-09-11');

        $score = app(ScoreService::class)->compute($employee, '2026-09');

        $this->assertEqualsWithDelta(100.0, $score['scores']['attendance_rate'], 0.001);
        $this->assertEqualsWithDelta(100.0, $score['scores']['punctuality'], 0.001);
        $this->assertEqualsWithDelta(100.0, $score['scores']['working_hours'], 0.001);
        $this->assertEqualsWithDelta(100.0, $score['scores']['leave_compliance'], 0.001);
        $this->assertEqualsWithDelta(100.0, $score['score'], 0.001);
    }

    public function test_statistics_page_shows_the_breakdown_and_the_formula(): void
    {
        $employee = $this->employeeWithDefaults();

        $this->seedMonth($employee, [
            '2026-09-08' => ['09:30', '18:00'],
            '2026-09-09' => ['09:30', '18:00'],
            '2026-09-10' => null,
            '2026-09-11' => null,
        ]);
        $this->approvedLeaveOn($employee, '2026-09-11');

        $this->actingAs($employee->user)
            ->get(route('statistics.index', ['month' => '2026-09']))
            ->assertOk()
            ->assertSee('Attendance Score Breakdown')
            ->assertSee('Attendance Rate')
            ->assertSee('Punctuality')
            ->assertSee('Working Hours')
            ->assertSee('Leave Compliance')
            ->assertSee('Final Score')
            ->assertSee('Formula:')
            ->assertSee('Attendance Rate × 40% + Punctuality × 25% + Working Hours × 20% + Leave Compliance × 15%')
            ->assertSee('87.15');
    }

    public function test_future_months_have_no_score_yet(): void
    {
        $employee = $this->employeeWithDefaults();
        $this->seedMonth($employee);

        $this->assertNull(app(ScoreService::class)->compute($employee, '2026-10'));

        $this->actingAs($employee->user)
            ->get(route('statistics.index', ['month' => '2026-10']))
            ->assertOk()
            ->assertDontSee('Attendance Score Breakdown');
    }

    private function employeeWithDefaults(): Employee
    {
        $ctx = $this->makeOrganization('OtScore '.uniqid());
        app(WorkforceDefaults::class)->apply($ctx['organization']);
        $user = $this->makeUser($ctx['organization'], 'employee', $ctx['roles']);

        return $this->employee = $this->makeEmployee($ctx['organization'], $user, 'EMP-SCORE');
    }

    /**
     * Punch every September weekday, with per-day overrides: a [in, out]
     * pair punches those times, null means no punches (absent / leave).
     *
     * @param  array<string, array{0: string, 1: string}|null>  $overrides
     */
    private function seedMonth(
        Employee $employee,
        array $overrides = [],
        string $defaultIn = '09:00',
        string $defaultOut = '18:00',
    ): void {
        foreach ($this->septemberWeekdays() as $date) {
            if (! array_key_exists($date, $overrides)) {
                $this->seedDay($employee, $date, $defaultIn, $defaultOut);

                continue;
            }

            $spec = $overrides[$date];

            if ($spec !== null) {
                $this->seedDay($employee, $date, $spec[0], $spec[1]);
            }
        }
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

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createPolicy(array $attributes = []): OvertimePolicy
    {
        return OvertimePolicy::withoutGlobalScopes()->create([
            'organization_id' => $this->employee->organization_id,
            ...OvertimePolicy::defaults(),
            ...$attributes,
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
