<?php

namespace Tests\Feature;

use App\Models\AttendanceEvent;
use App\Models\Employee;
use App\Models\Organization;
use App\Models\Payslip;
use App\Models\User;
use App\Notifications\MonthlyStatusReady;
use App\Notifications\PayslipReady;
use App\Services\AttendanceService;
use App\Services\MonthlyStatusService;
use App\Services\WorkforceDefaults;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\CreatesOrganizations;
use Tests\TestCase;

/**
 * Phase 7 salary + monthly status notifications: payslip finalize/revise
 * and the month-close status push (score + band).
 */
class Phase7NotificationTest extends TestCase
{
    use CreatesOrganizations;
    use RefreshDatabase;

    /**
     * Org + defaults + admin + employee with a 30,000 + 2,000 salary record.
     *
     * @return array{0: Organization, 1: User, 2: User, 3: Employee}
     */
    private function bootPayslip(string $name): array
    {
        $ctx = $this->makeOrganization($name);
        app(WorkforceDefaults::class)->apply($ctx['organization']);

        $admin = $this->makeUser($ctx['organization'], 'company_admin', $ctx['roles']);
        $user = $this->makeUser($ctx['organization'], 'employee', $ctx['roles']);
        $employee = $this->makeEmployee($ctx['organization'], $user, 'EMP-N7');

        $this->actingAs($admin)
            ->post(route('salary.store', $employee), [
                'basic_salary' => 30000,
                'allowances' => 2000,
                'effective_from' => '2026-01-01',
            ])
            ->assertRedirect(route('salary.show', $employee));

        return [$ctx['organization'], $admin, $user, $employee];
    }

    private function punchMonth(Employee $employee, User $user, string $month): void
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

    public function test_finalizing_a_payslip_notifies_the_employee(): void
    {
        Notification::fake();
        $this->travelTo(Carbon::parse('2026-10-05 10:00:00'));

        [, $admin, $user, $employee] = $this->bootPayslip('NotifyFinal');
        $this->punchMonth($employee, $user, '2026-09');

        $this->actingAs($admin)
            ->post(route('payslips.store', $employee), ['period' => '2026-09'])
            ->assertRedirect(route('salary.show', $employee));

        Notification::assertSentTo($user, PayslipReady::class);

        Notification::assertSentTo($user, PayslipReady::class,
            fn (PayslipReady $n) => $n->title() === 'Payslip ready'
                && str_contains($n->body(), 'finalized')
                && str_contains($n->body(), 'net'),
        );
    }

    public function test_revising_a_payslip_notifies_the_employee_again(): void
    {
        Notification::fake();
        $this->travelTo(Carbon::parse('2026-10-05 10:00:00'));

        [, $admin, $user, $employee] = $this->bootPayslip('NotifyRevise');
        $this->punchMonth($employee, $user, '2026-09');

        $this->actingAs($admin)
            ->post(route('payslips.store', $employee), ['period' => '2026-09'])
            ->assertRedirect(route('salary.show', $employee));

        $payslip = Payslip::withoutGlobalScopes()->where('employee_id', $employee->id)->firstOrFail();

        $this->actingAs($admin)
            ->put(route('payslips.revise', [$employee, $payslip]), ['notes' => 'corrected'])
            ->assertRedirect(route('salary.show', $employee));

        Notification::assertSentToTimes($user, PayslipReady::class, 2);
        Notification::assertSentTo($user, PayslipReady::class,
            fn (PayslipReady $n) => $n->title() === 'Payslip revised'
                && str_contains($n->body(), 'revision 2 issued'),
        );
    }

    public function test_generating_a_monthly_status_notifies_the_employee_with_their_band(): void
    {
        Notification::fake();
        $this->travelTo(Carbon::parse('2026-09-30 23:00:00'));

        $ctx = $this->makeOrganization('NotifyStatus');
        app(WorkforceDefaults::class)->apply($ctx['organization']);

        $user = $this->makeUser($ctx['organization'], 'employee', $ctx['roles']);
        $employee = $this->makeEmployee($ctx['organization'], $user, 'EMP-NS');

        $service = app(AttendanceService::class);
        foreach (['2026-09-01', '2026-09-02', '2026-09-03'] as $date) {
            foreach ([[AttendanceEvent::CHECK_IN, '09:00'], [AttendanceEvent::CHECK_OUT, '18:00']] as [$type, $time]) {
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
            $service->deriveDaily($employee, $date);
        }

        $this->assertSame(1, app(MonthlyStatusService::class)->generate('2026-09'));

        Notification::assertSentTo($user, MonthlyStatusReady::class,
            fn (MonthlyStatusReady $n) => $n->title() === 'Monthly status ready'
                && str_contains($n->body(), 'Sep 2026')
                && preg_match('/score \d+\.\d, (Excellent|Good|Needs Attention|At Risk)\./', $n->body()) === 1,
        );

        // Finalized rows are skipped, so no duplicate notification on re-run.
        $this->assertSame(0, app(MonthlyStatusService::class)->generate('2026-09'));
        Notification::assertSentToTimes($user, MonthlyStatusReady::class, 1);
    }
}
