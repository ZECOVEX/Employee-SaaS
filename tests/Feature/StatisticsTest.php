<?php

namespace Tests\Feature;

use App\Models\AttendanceEvent;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Organization;
use App\Models\User;
use App\Services\AttendanceService;
use App\Services\SalaryCalculator;
use App\Services\WorkforceDefaults;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesOrganizations;
use Tests\TestCase;

class StatisticsTest extends TestCase
{
    use CreatesOrganizations;
    use RefreshDatabase;

    /**
     * Org + defaults + employee with a salary record (30,000 + 2,000 allowances,
     * effective 2026-01-01) so the calculator has a base to work from.
     *
     * @return array{0: Organization, 1: User, 2: Employee, 3: User}
     */
    private function bootStats(string $name): array
    {
        $ctx = $this->makeOrganization($name);
        app(WorkforceDefaults::class)->apply($ctx['organization']);

        $user = $this->makeUser($ctx['organization'], 'employee', $ctx['roles']);
        $employee = $this->makeEmployee($ctx['organization'], $user, 'EMP-ST1');
        $admin = $this->makeUser($ctx['organization'], 'company_admin', $ctx['roles']);

        $this->actingAs($admin)
            ->post(route('salary.store', $employee), [
                'basic_salary' => 30000,
                'allowances' => 2000,
                'effective_from' => '2026-01-01',
            ])
            ->assertRedirect(route('salary.show', $employee));

        return [$ctx['organization'], $user, $employee, $admin];
    }

    public function test_statistics_requires_an_employee_profile(): void
    {
        $ctx = $this->makeOrganization('StatsGate');
        $admin = $this->makeUser($ctx['organization'], 'company_admin', $ctx['roles']);
        $user = $this->makeUser($ctx['organization'], 'employee', $ctx['roles']);
        $this->makeEmployee($ctx['organization'], $user, 'EMP-ST0');

        $this->actingAs($admin)->get(route('statistics.index'))->assertForbidden();
        $this->actingAs($user)->get(route('statistics.index'))->assertOk();
    }

    public function test_settings_persists_and_validates_salary_rules(): void
    {
        $ctx = $this->makeOrganization('StatsRules');
        $admin = $this->makeUser($ctx['organization'], 'company_admin', $ctx['roles']);

        $this->actingAs($admin)
            ->put(route('settings.update'), [
                'name' => $ctx['organization']->name,
                'timezone' => 'UTC',
                'debounce_seconds' => 15,
                'time_format' => '24h',
                'currency' => 'USD',
                'late_deduction' => 'none',
                'deduction_grace_minutes' => 30,
                'deduction_rounding' => 'nearest',
                'absence_deduction' => 'half_day',
                'half_day_deduction' => 'none',
                'unpaid_leave_deduction' => '0',
                'max_deduction_percent' => 25,
            ])
            ->assertRedirect(route('settings.edit'));

        $rules = $ctx['organization']->fresh()->salaryRules();
        $this->assertSame('USD', $rules['currency']);
        $this->assertSame('none', $rules['late_deduction']);
        $this->assertSame(30, $rules['deduction_grace_minutes']);
        $this->assertSame('nearest', $rules['deduction_rounding']);
        $this->assertSame('half_day', $rules['absence_deduction']);
        $this->assertSame('none', $rules['half_day_deduction']);
        $this->assertFalse($rules['unpaid_leave_deduction']);
        $this->assertSame(25, $rules['max_deduction_percent']);

        // Invalid values are rejected without changing anything.
        $this->actingAs($admin)
            ->put(route('settings.update'), [
                'name' => $ctx['organization']->name,
                'timezone' => 'UTC',
                'debounce_seconds' => 15,
                'time_format' => '24h',
                'late_deduction' => 'per_hour',
                'max_deduction_percent' => 150,
                'currency' => '$$$',
            ])
            ->assertSessionHasErrors(['late_deduction', 'max_deduction_percent', 'currency']);

        $rules = $ctx['organization']->fresh()->salaryRules();
        $this->assertSame('none', $rules['late_deduction']);
        $this->assertSame(25, $rules['max_deduction_percent']);
        $this->assertSame('USD', $rules['currency']);
    }

    public function test_settings_page_renders_salary_rules_section(): void
    {
        $ctx = $this->makeOrganization('StatsForm');
        $admin = $this->makeUser($ctx['organization'], 'company_admin', $ctx['roles']);

        $this->actingAs($admin)
            ->get(route('settings.edit'))
            ->assertOk()
            ->assertSee('Salary & deductions')
            ->assertSee('late_deduction')
            ->assertSee('max_deduction_percent');
    }

    public function test_calculator_estimates_progressive_salary_for_the_month(): void
    {
        $this->travelTo(Carbon::parse('2026-09-15 12:00:00')); // Tuesday
        [, $user, $employee] = $this->bootStats('StatsBase');

        $stats = app(SalaryCalculator::class)->estimate($employee, '2026-09');

        $this->assertNotNull($stats);
        // Sep 2026: 22 Mon–Fri workdays, no holidays; today is the 15th.
        $this->assertSame(22, $stats['scheduled_days']);
        $this->assertSame(11, $stats['completed_days']);
        $this->assertSame(11, $stats['remaining_days']);
        $this->assertSame(30, count($stats['breakdown']));

        $this->assertEqualsWithDelta(1454.55, $stats['daily_rate'], 0.01);      // 32,000 / 22
        $this->assertEqualsWithDelta(181.82, $stats['hourly_rate'], 0.01);       // daily / 8h
        $this->assertEqualsWithDelta(8.0, $stats['expected_hours_per_day'], 0.01);

        // No attendance or leave at all: every elapsed workday counts as absent.
        $this->assertSame(11, $stats['absent_days']);
        $this->assertSame(0, $stats['late_minutes']);
        $this->assertEqualsWithDelta(0.0, $stats['late_deduction'], 0.001);

        $expectedAbsence = 11 * 1454.55; // per-day rounding, then summed
        $this->assertEqualsWithDelta($expectedAbsence, $stats['attendance_deduction_total'], 0.01);
        $this->assertEqualsWithDelta(32000 - $expectedAbsence, $stats['estimated_net'], 0.01);
        $this->assertFalse($stats['capped']);

        // Breakdown rows always sum to the reported total.
        $rowSum = array_sum(array_column($stats['breakdown'], 'deduction'));
        $this->assertEqualsWithDelta($stats['attendance_deduction_total'], $rowSum, 0.001);

        $future = array_filter($stats['breakdown'], fn ($row) => $row['status'] === 'FUTURE');
        $this->assertSame(11, count($future)); // remaining scheduled workdays (Sep 16–30)
    }

    public function test_late_leave_and_unpaid_deductions_follow_rules(): void
    {
        $this->travelTo(Carbon::parse('2026-09-10 20:00:00')); // Thursday
        [, $user, $employee] = $this->bootStats('StatsDeduct');

        $service = app(AttendanceService::class);
        // Mon 7th: 45 minutes late (grace 15 in schedule → LATE status).
        $service->recordManual($employee, AttendanceEvent::CHECK_IN, Carbon::parse('2026-09-07 09:45'), 'seed', $user->id);
        $service->recordManual($employee, AttendanceEvent::CHECK_OUT, Carbon::parse('2026-09-07 18:00'), 'seed', $user->id);
        // Tue 8th: left after lunch → HALF_DAY.
        $service->recordManual($employee, AttendanceEvent::CHECK_IN, Carbon::parse('2026-09-08 09:00'), 'seed', $user->id);
        $service->recordManual($employee, AttendanceEvent::CHECK_OUT, Carbon::parse('2026-09-08 12:30'), 'seed', $user->id);

        $unpaid = LeaveType::withoutGlobalScopes()
            ->where('organization_id', $employee->organization_id)
            ->where('code', 'UNPAID')
            ->firstOrFail();
        $this->approveLeave($employee, $unpaid, '2026-09-09');
        $annual = LeaveType::withoutGlobalScopes()
            ->where('organization_id', $employee->organization_id)
            ->where('code', 'ANNUAL')
            ->firstOrFail();
        $this->approveLeave($employee, $annual, '2026-09-10');

        $daily = 32000 / 22;      // 1454.5454…
        $hourly = $daily / 8;     // 181.8181…
        $late = (45 / 60) * $hourly;

        $stats = app(SalaryCalculator::class)->estimate($employee, '2026-09');

        $this->assertSame(8, $stats['completed_days']);
        $this->assertSame(14, $stats['remaining_days']);
        $this->assertSame(4, $stats['absent_days']);       // Sep 1–4
        $this->assertSame(1, $stats['late_days']);
        $this->assertSame(1, $stats['half_days']);
        $this->assertSame(1, $stats['unpaid_leave_days']);
        $this->assertSame(1, $stats['leave_days']);
        $this->assertSame(45, $stats['late_minutes']);

        $this->assertEqualsWithDelta($late, $stats['late_deduction'], 0.02);
        // 4 absent days at full rate + one HALF_DAY at half rate (per-day rounding).
        $this->assertEqualsWithDelta(4 * 1454.55 + 727.27, $stats['absence_deduction'], 0.02);
        $this->assertEqualsWithDelta(1454.55, $stats['unpaid_leave_deduction'], 0.02);

        $expectedTotal = $stats['late_deduction'] + $stats['absence_deduction'] + $stats['unpaid_leave_deduction'];
        $this->assertEqualsWithDelta($expectedTotal, $stats['attendance_deduction_total'], 0.001);
        $this->assertEqualsWithDelta(32000 - $expectedTotal, $stats['estimated_net'], 0.001);

        $byDate = array_column($stats['breakdown'], null, 'date');
        $this->assertSame('LATE', $byDate['2026-09-07']['status']);
        $this->assertSame('HALF_DAY', $byDate['2026-09-08']['status']);
        $this->assertSame('UNPAID LEAVE', $byDate['2026-09-09']['status']);
        $this->assertSame('LEAVE', $byDate['2026-09-10']['status']);
        $this->assertSame('ABSENT', $byDate['2026-09-01']['status']);
        $this->assertSame('WEEKEND', $byDate['2026-09-05']['status']);
        $this->assertSame('FUTURE', $byDate['2026-09-11']['status']);
        $this->assertEqualsWithDelta(0.0, $byDate['2026-09-10']['deduction'], 0.001); // paid leave
        $this->assertEqualsWithDelta(1454.55, $byDate['2026-09-09']['deduction'], 0.01);
    }

    public function test_configurable_rules_change_the_estimate(): void
    {
        $this->travelTo(Carbon::parse('2026-09-10 20:00:00'));
        [$org, $user, $employee, $admin] = $this->bootStats('StatsTune');

        $service = app(AttendanceService::class);
        $service->recordManual($employee, AttendanceEvent::CHECK_IN, Carbon::parse('2026-09-07 09:45'), 'seed', $user->id);
        $service->recordManual($employee, AttendanceEvent::CHECK_OUT, Carbon::parse('2026-09-07 18:00'), 'seed', $user->id);

        $unpaid = LeaveType::withoutGlobalScopes()
            ->where('organization_id', $org->id)
            ->where('code', 'UNPAID')
            ->firstOrFail();
        $this->approveLeave($employee, $unpaid, '2026-09-09');

        $before = app(SalaryCalculator::class)->estimate($employee, '2026-09');
        $this->assertGreaterThan(0.0, $before['late_deduction']);
        $this->assertGreaterThan(0.0, $before['unpaid_leave_deduction']);

        // Disable late deductions and unpaid-leave deductions; cap at 10%.
        $this->actingAs($admin)
            ->put(route('settings.update'), [
                'name' => $org->name,
                'timezone' => 'UTC',
                'debounce_seconds' => 15,
                'time_format' => '24h',
                'late_deduction' => 'none',
                'unpaid_leave_deduction' => '0',
                'max_deduction_percent' => 10,
            ])
            ->assertRedirect(route('settings.edit'));

        $after = app(SalaryCalculator::class)->estimate($employee->fresh(), '2026-09');

        $this->assertEqualsWithDelta(0.0, $after['late_deduction'], 0.001);
        $this->assertEqualsWithDelta(0.0, $after['unpaid_leave_deduction'], 0.001);
        // 10% of the 32,000 monthly base.
        $this->assertEqualsWithDelta(3200.0, $after['attendance_deduction_total'], 0.1);
        $this->assertTrue($after['capped']);
        $this->assertLessThan($before['attendance_deduction_total'], $after['attendance_deduction_total']);

        // The status stays unpaid leave — only the money treatment changed.
        $byDate = array_column($after['breakdown'], null, 'date');
        $this->assertSame('UNPAID LEAVE', $byDate['2026-09-09']['status']);
        $this->assertEqualsWithDelta(0.0, $byDate['2026-09-09']['deduction'], 0.001);
    }

    public function test_statistics_page_renders_estimate_and_empty_state(): void
    {
        $this->travelTo(Carbon::parse('2026-09-15 12:00:00'));

        // Without a salary record → friendly empty state.
        $ctx = $this->makeOrganization('StatsView');
        app(WorkforceDefaults::class)->apply($ctx['organization']);
        $user = $this->makeUser($ctx['organization'], 'employee', $ctx['roles']);
        $this->makeEmployee($ctx['organization'], $user, 'EMP-ST9');

        $this->actingAs($user)
            ->get(route('statistics.index', ['month' => '2026-09']))
            ->assertOk()
            ->assertSee('Statistics — September 2026')
            ->assertSee('No salary record covers this period')
            ->assertSee('Statistics'); // nav link for employees

        // With a record → the full estimate renders.
        [, $statsUser] = $this->bootStats('StatsView2');

        $response = $this->actingAs($statsUser)
            ->get(route('statistics.index', ['month' => '2026-09']))
            ->assertOk()
            ->assertSee('Estimated net salary')
            ->assertSee('Daily breakdown')
            ->assertSee('Salary basis')
            ->assertSee('1,454.55')   // daily rate
            ->assertSee('181.82')     // hourly rate
            ->assertSee('← Aug 2026'); // previous-month link

        // Days without attendance show as ABSENT in the breakdown.
        $response->assertSee('ABSENT');
    }

    public function test_invalid_month_parameter_is_rejected(): void
    {
        $this->travelTo(Carbon::parse('2026-09-15 12:00:00'));
        [, $user] = $this->bootStats('StatsMonth');

        $this->actingAs($user)
            ->from(route('statistics.index'))
            ->get(route('statistics.index', ['month' => '2026-13']))
            ->assertSessionHasErrors('month');

        $this->actingAs($user)
            ->from(route('statistics.index'))
            ->get(route('statistics.index', ['month' => 'september']))
            ->assertSessionHasErrors('month');
    }

    public function test_statistics_are_self_only(): void
    {
        $this->travelTo(Carbon::parse('2026-09-15 12:00:00'));
        [, $user, $employee] = $this->bootStats('StatsSelf');
        $otherUser = $this->makeUser($employee->organization, 'employee', null, [
            'email' => 'other-'.uniqid().'@example.test',
            'name' => 'Zed Otherperson',
        ]);
        $this->makeEmployee($employee->organization, $otherUser, 'EMP-ST2');

        // Each user only ever sees their own numbers (no employee selector exists).
        $own = $this->actingAs($user)->get(route('statistics.index'))->assertOk();
        $own->assertDontSee('Zed Otherperson');

        $this->actingAs($otherUser)->get(route('statistics.index'))->assertOk();
    }

    /**
     * Create an approved one-day leave request for the given date.
     */
    private function approveLeave($employee, LeaveType $type, string $date): LeaveRequest
    {
        $request = new LeaveRequest([
            'start_date' => $date,
            'end_date' => $date,
            'days' => 1,
            'reason' => 'Trip',
            'status' => LeaveRequest::APPROVED,
        ]);
        $request->organization()->associate($employee->organization);
        $request->employee()->associate($employee);
        $request->leaveType()->associate($type);
        $request->save();

        return $request;
    }
}
