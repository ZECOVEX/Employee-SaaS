<?php

namespace Tests\Feature;

use App\Models\AttendanceEvent;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Organization;
use App\Models\Payslip;
use App\Models\User;
use App\Services\AttendanceService;
use App\Services\LeaveService;
use App\Services\WorkforceDefaults;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesOrganizations;
use Tests\TestCase;

class ReportTest extends TestCase
{
    use CreatesOrganizations;
    use RefreshDatabase;

    /**
     * Org + defaults + admin (reports.view) + employee A with a salary
     * record effective 2026-06-01 (30,000 + 2,000).
     *
     * @return array{0: Organization, 1: User, 2: User, 3: Employee}
     */
    private function bootReports(string $name): array
    {
        $ctx = $this->makeOrganization($name);
        app(WorkforceDefaults::class)->apply($ctx['organization']);

        $admin = $this->makeUser($ctx['organization'], 'company_admin', $ctx['roles']);
        $user = $this->makeUser($ctx['organization'], 'employee', $ctx['roles'], [
            'name' => 'Alice Reportsn',
        ]);
        $employee = $this->makeEmployee($ctx['organization'], $user, 'EMP-R1');

        $this->actingAs($admin)
            ->post(route('salary.store', $employee), [
                'basic_salary' => 30000,
                'allowances' => 2000,
                'effective_from' => '2026-06-01',
            ])
            ->assertRedirect(route('salary.show', $employee));

        return [$ctx['organization'], $admin, $user, $employee];
    }

    /**
     * Full-attendance month: every weekday gets 09:00 → 18:00 (PRESENT).
     */
    private function punchMonth($employee, $user, string $month): void
    {
        $service = app(AttendanceService::class);
        $cursor = Carbon::createFromFormat('!Y-m-d', $month.'-01');
        $end = $cursor->copy()->endOfMonth();

        while ($cursor->lte($end)) {
            if (in_array((int) $cursor->dayOfWeekIso, [1, 2, 3, 4, 5], true)) {
                $service->recordManual($employee, AttendanceEvent::CHECK_IN, $cursor->copy()->setTime(9, 0), 'seed', $user->id);
                $service->recordManual($employee, AttendanceEvent::CHECK_OUT, $cursor->copy()->setTime(18, 0), 'seed', $user->id);
            }
            $cursor->addDay();
        }
    }

    /**
     * Approve a pending leave request through the real service so LEAVE
     * rows get stamped onto the daily attendance table.
     */
    private function approveLeave(Employee $employee, string $code, string $start, string $end, int $reviewerId): void
    {
        $type = LeaveType::withoutGlobalScopes()
            ->where('organization_id', $employee->organization_id)
            ->where('code', $code)
            ->firstOrFail();

        $request = new LeaveRequest([
            'start_date' => $start,
            'end_date' => $end,
            'days' => Carbon::parse($start)->diffInDays(Carbon::parse($end)) + 1,
            'reason' => 'Trip',
            'status' => LeaveRequest::PENDING,
        ]);
        $request->organization()->associate($employee->organization);
        $request->employee()->associate($employee);
        $request->leaveType()->associate($type);
        $request->save();

        app(LeaveService::class)->approve($request, $reviewerId);
    }

    public function test_reports_require_reports_view_and_salary_is_extra_restricted(): void
    {
        $this->travelTo(Carbon::parse('2026-09-15 12:00:00'));
        [$org, $admin,, $employee] = $this->bootReports('RepPerm');

        // Plain employees get 403 everywhere (middleware).
        $employeeUser = $employee->user;
        foreach (['reports.attendance', 'reports.monthly', 'reports.salary', 'analytics.index'] as $routeName) {
            $this->actingAs($employeeUser)->get(route($routeName))->assertForbidden();
        }
        $this->actingAs($employeeUser)
            ->get(route('reports.export', ['type' => 'attendance']))
            ->assertForbidden();

        // Manager has reports.view but not salary.view.
        $manager = $this->makeUser($org, 'manager');
        $this->actingAs($manager)->get(route('reports.attendance'))->assertOk();
        $this->actingAs($manager)->get(route('reports.monthly'))->assertOk();
        $this->actingAs($manager)->get(route('reports.salary'))->assertForbidden();
        $this->actingAs($manager)
            ->get(route('reports.export', ['type' => 'salary', 'month' => '2026-09']))
            ->assertForbidden();

        // HR sees everything, including pay data.
        $hr = $this->makeUser($org, 'hr');
        $this->actingAs($hr)->get(route('reports.salary'))->assertOk();

        // Admin sees the analytics dashboard too.
        $this->actingAs($admin)->get(route('analytics.index'))->assertOk();

        // Unknown export type is a 404, not a 500.
        $this->actingAs($admin)
            ->get(route('reports.export', ['type' => 'nope']))
            ->assertNotFound();
    }

    public function test_attendance_report_filters_and_renders(): void
    {
        $this->travelTo(Carbon::parse('2026-09-15 12:00:00'));
        [, $admin, $user, $employee] = $this->bootReports('RepAtt');

        $service = app(AttendanceService::class);
        // Mon 7th: clean PRESENT day.
        $service->recordManual($employee, AttendanceEvent::CHECK_IN, Carbon::parse('2026-09-07 09:00'), 'seed', $user->id);
        $service->recordManual($employee, AttendanceEvent::CHECK_OUT, Carbon::parse('2026-09-07 18:00'), 'seed', $user->id);
        // Tue 8th: 45 minutes late (grace 15 → LATE).
        $service->recordManual($employee, AttendanceEvent::CHECK_IN, Carbon::parse('2026-09-08 09:45'), 'seed', $user->id);
        $service->recordManual($employee, AttendanceEvent::CHECK_OUT, Carbon::parse('2026-09-08 18:00'), 'seed', $user->id);

        $page = $this->actingAs($admin)->get(route('reports.attendance', [
            'from' => '2026-09-07',
            'to' => '2026-09-08',
        ]));
        $page->assertOk()
            ->assertSee('Rows: 2')
            ->assertSee('Alice Reportsn')
            ->assertSee('PRESENT')
            ->assertSee('LATE')
            ->assertSee('09:45');

        // Status filter narrows to the single LATE row.
        $this->actingAs($admin)
            ->get(route('reports.attendance', [
                'from' => '2026-09-07',
                'to' => '2026-09-08',
                'status' => 'LATE',
            ]))
            ->assertOk()
            ->assertSee('Rows: 1');

        // Employee filter for someone with no rows → friendly empty state.
        $bobUser = $this->makeUser($employee->organization, 'employee', null, [
            'name' => 'Bob Nodata',
            'email' => 'bob-'.uniqid().'@example.test',
        ]);
        $bob = $this->makeEmployee($employee->organization, $bobUser, 'EMP-R9');
        $this->actingAs($admin)
            ->get(route('reports.attendance', [
                'from' => '2026-09-07',
                'to' => '2026-09-08',
                'employee_id' => $bob->id,
            ]))
            ->assertOk()
            ->assertSee('No attendance rows match these filters.');
    }

    public function test_attendance_report_validation(): void
    {
        $this->travelTo(Carbon::parse('2026-09-15 12:00:00'));
        [, $admin] = $this->bootReports('RepValid');

        $this->actingAs($admin)
            ->from(route('reports.attendance'))
            ->get(route('reports.attendance', ['from' => '2026-09-30', 'to' => '2026-09-01']))
            ->assertSessionHasErrors('to');

        $this->actingAs($admin)
            ->from(route('reports.attendance'))
            ->get(route('reports.attendance', ['from' => 'yesterday']))
            ->assertSessionHasErrors('from');

        $this->actingAs($admin)
            ->from(route('reports.monthly'))
            ->get(route('reports.monthly', ['month' => '2026-13']))
            ->assertSessionHasErrors('month');

        $this->actingAs($admin)
            ->from(route('reports.attendance'))
            ->get(route('reports.attendance', ['status' => 'SICK']))
            ->assertSessionHasErrors('status');
    }

    public function test_attendance_csv_export_downloads_filtered_rows(): void
    {
        $this->travelTo(Carbon::parse('2026-09-15 12:00:00'));
        [, $admin, $user, $employee] = $this->bootReports('RepCsv');

        $service = app(AttendanceService::class);
        $service->recordManual($employee, AttendanceEvent::CHECK_IN, Carbon::parse('2026-09-07 09:00'), 'seed', $user->id);
        $service->recordManual($employee, AttendanceEvent::CHECK_OUT, Carbon::parse('2026-09-07 18:00'), 'seed', $user->id);

        $response = $this->actingAs($admin)->get(route('reports.export', [
            'type' => 'attendance',
            'from' => '2026-09-07',
            'to' => '2026-09-08',
        ]));

        $response->assertOk()->assertDownload('attendance_2026-09-07_2026-09-08.csv');
        $response->assertHeader('content-type', 'text/csv; charset=UTF-8');

        $csv = $response->streamedContent();
        $lines = array_values(array_filter(explode("\n", trim($csv))));
        $this->assertStringStartsWith('Date,Employee,Code,Department,Status', $lines[0]);
        $this->assertCount(2, $lines); // header + one data row
        $this->assertStringContainsString('2026-09-07', $lines[1]);
        $this->assertStringContainsString('Alice Reportsn', $lines[1]);
        $this->assertStringContainsString('PRESENT', $lines[1]);
        // 8 scheduled hours worked, no late/OT minutes.
        $this->assertStringContainsString(',480,0,0,', $lines[1]);
    }

    public function test_monthly_report_aggregates_present_absent_and_leave(): void
    {
        $this->travelTo(Carbon::parse('2026-10-05 12:00:00'));
        [, $admin, $user, $employee] = $this->bootReports('RepMonth');
        $this->punchMonth($employee, $user, '2026-09');

        // Second employee: never shows up → every scheduled day absent.
        $bobUser = $this->makeUser($employee->organization, 'employee', null, [
            'name' => 'Bob Absent',
            'email' => 'bob-'.uniqid().'@example.test',
        ]);
        $bob = $this->makeEmployee($employee->organization, $bobUser, 'EMP-R8');

        // Third employee: approved unpaid leave across the whole month →
        // stamped LEAVE rows on every scheduled day, never absent.
        $carolUser = $this->makeUser($employee->organization, 'employee', null, [
            'name' => 'Carol Onleave',
            'email' => 'carol-'.uniqid().'@example.test',
        ]);
        $carol = $this->makeEmployee($employee->organization, $carolUser, 'EMP-R7');
        $this->approveLeave($carol, 'UNPAID', '2026-09-01', '2026-09-30', $admin->id);

        $csv = $this->actingAs($admin)
            ->get(route('reports.export', ['type' => 'monthly', 'month' => '2026-09']))
            ->assertOk()
            ->assertDownload('monthly_report_2026-09.csv')
            ->streamedContent();

        $rows = [];
        foreach (array_filter(explode("\n", trim($csv))) as $line) {
            $rows[str_getcsv($line)[0]] = str_getcsv($line);
        }
        unset($rows['Employee']); // header line

        // Employee, Code, Dept, Scheduled, Present, Absent, Late, Leave, Worked, OT, Late minutes
        $this->assertSame('22', $rows['Alice Reportsn'][3]);
        $this->assertSame('22', $rows['Alice Reportsn'][4]);  // present
        $this->assertSame('0', $rows['Alice Reportsn'][5]);   // absent
        $this->assertSame('176', $rows['Alice Reportsn'][8]); // 22 × 8h

        $this->assertSame('22', $rows['Bob Absent'][5]); // absent, nothing else
        $this->assertSame('0', $rows['Bob Absent'][4]);

        $this->assertSame('22', $rows['Carol Onleave'][7]); // leave
        $this->assertSame('0', $rows['Carol Onleave'][5]);  // never absent

        // The HTML page renders the same numbers with Score/Status reserved.
        $this->actingAs($admin)
            ->get(route('reports.monthly', ['month' => '2026-09']))
            ->assertOk()
            ->assertSee('Alice Reportsn')
            ->assertSee('Bob Absent')
            ->assertSee('Carol Onleave')
            ->assertSee('Totals (3 employees)');
    }

    public function test_salary_report_shows_effective_records_only(): void
    {
        $this->travelTo(Carbon::parse('2026-10-05 12:00:00'));
        [, $admin,, $employee] = $this->bootReports('RepSal');

        // Another employee without any salary record.
        $bobUser = $this->makeUser($employee->organization, 'employee', null, [
            'name' => 'Bob Nosalary',
            'email' => 'bob-'.uniqid().'@example.test',
        ]);
        $this->makeEmployee($employee->organization, $bobUser, 'EMP-R6');

        // June 2026 onward: the record exists (effective 2026-06-01).
        $this->actingAs($admin)
            ->get(route('reports.salary', ['month' => '2026-09']))
            ->assertOk()
            ->assertSee('Effective salary records')
            ->assertSee('September 2026')
            ->assertSee('Alice Reportsn')
            ->assertSee('30,000.00')
            ->assertSee('32,000.00') // gross
            ->assertSee('No salary record covers this period'); // Bob

        // May 2026: before the record took effect → no numbers at all.
        $this->actingAs($admin)
            ->get(route('reports.salary', ['month' => '2026-05']))
            ->assertOk()
            ->assertDontSee('30,000.00')
            ->assertSee('No salary record covers this period');

        // A raise later on must not rewrite earlier months: add a new record
        // from September and re-check that June still shows the old numbers.
        $this->actingAs($admin)
            ->post(route('salary.store', $employee), [
                'basic_salary' => 40000,
                'effective_from' => '2026-09-01',
            ]);

        $this->actingAs($admin)
            ->get(route('reports.salary', ['month' => '2026-06']))
            ->assertOk()
            ->assertSee('30,000.00')
            ->assertDontSee('40,000.00');
        $this->actingAs($admin)
            ->get(route('reports.salary', ['month' => '2026-09']))
            ->assertOk()
            ->assertSee('40,000.00');
    }

    public function test_analytics_dashboard_renders_charts_and_respects_paywall(): void
    {
        $this->travelTo(Carbon::parse('2026-10-05 12:00:00'));
        [$org, $admin, $user, $employee] = $this->bootReports('RepAn');
        $this->punchMonth($employee, $user, '2026-09');

        // Finalize one payslip so the payroll chart has data.
        $this->actingAs($admin)
            ->post(route('payslips.store', $employee), ['period' => '2026-09'])
            ->assertSessionHas('status');
        $this->assertGreaterThan(0, Payslip::withoutGlobalScopes()->count());

        $page = $this->actingAs($admin)->get(route('analytics.index'));
        $page->assertOk()
            ->assertSee('Analytics')
            ->assertSee('Attendance trend')
            ->assertSee('Departments')
            ->assertSee('Late arrivals by weekday')
            ->assertSee('Active employees')
            ->assertSee('Finalized payroll')   // admin has salary.view
            ->assertSee('Reports');            // nav link

        // HR can view analytics without the payroll card (no salary.view? HR has it)…
        $hr = $this->makeUser($org, 'hr');
        $this->actingAs($hr)->get(route('analytics.index'))
            ->assertOk()
            ->assertSee('Finalized payroll');

        // Manager: charts yes, payroll no (no salary.view).
        $manager = $this->makeUser($org, 'manager');
        $this->actingAs($manager)->get(route('analytics.index'))
            ->assertOk()
            ->assertSee('Attendance trend')
            ->assertDontSee('Finalized payroll');
    }

    public function test_reports_are_tenant_isolated(): void
    {
        $this->travelTo(Carbon::parse('2026-09-15 12:00:00'));
        [, $admin,, $employee] = $this->bootReports('RepIsoA');
        $service = app(AttendanceService::class);
        $service->recordManual($employee, AttendanceEvent::CHECK_IN, Carbon::parse('2026-09-07 09:00'), 'seed', $employee->user_id);
        $service->recordManual($employee, AttendanceEvent::CHECK_OUT, Carbon::parse('2026-09-07 18:00'), 'seed', $employee->user_id);

        $ctxB = $this->makeOrganization('RepIsoB');
        $adminB = $this->makeUser($ctxB['organization'], 'company_admin', $ctxB['roles']);

        // Org B never sees org A's rows, employees or salary numbers.
        $this->actingAs($adminB)
            ->get(route('reports.attendance', ['from' => '2026-09-01', 'to' => '2026-09-30']))
            ->assertOk()
            ->assertSee('No attendance rows match these filters.')
            ->assertDontSee('Alice Reportsn');

        $csv = $this->actingAs($adminB)
            ->get(route('reports.export', ['type' => 'attendance', 'from' => '2026-09-01', 'to' => '2026-09-30']))
            ->assertOk()
            ->streamedContent();
        $this->assertStringNotContainsString('Alice Reportsn', $csv);
        $this->assertCount(1, array_filter(explode("\n", trim($csv)))); // header only

        $this->actingAs($adminB)
            ->get(route('reports.salary', ['month' => '2026-09']))
            ->assertOk()
            ->assertDontSee('30,000.00');

        $this->actingAs($adminB)
            ->get(route('analytics.index'))
            ->assertOk()
            ->assertDontSee('Alice Reportsn');
    }
}
