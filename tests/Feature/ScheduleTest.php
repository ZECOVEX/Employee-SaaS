<?php

namespace Tests\Feature;

use App\Models\AttendanceEvent;
use App\Models\DailyAttendance;
use App\Models\WorkSchedule;
use App\Services\AttendanceService;
use App\Services\WorkforceDefaults;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesOrganizations;
use Tests\TestCase;

class ScheduleTest extends TestCase
{
    use CreatesOrganizations;
    use RefreshDatabase;

    public function test_schedule_changes_are_effective_dated_and_do_not_rewrite_history(): void
    {
        $this->travelTo(Carbon::parse('2026-09-30 10:00:00')); // Wednesday

        $ctx = $this->makeOrganization('SchedCo');
        app(WorkforceDefaults::class)->apply($ctx['organization']);
        $admin = $this->makeUser($ctx['organization'], 'company_admin', $ctx['roles']);
        $user = $this->makeUser($ctx['organization'], 'employee', $ctx['roles']);
        $employee = $this->makeEmployee($ctx['organization'], $user, 'EMP-SC1');

        // Yesterday's arrival under the 09:00 schedule → 30 minutes late.
        $service = app(AttendanceService::class);
        $service->recordManual(
            $employee,
            AttendanceEvent::CHECK_IN,
            Carbon::parse('2026-09-29 09:30:00'),
            'seed',
            $admin->id,
        );
        $yesterday = DailyAttendance::withoutGlobalScopes()
            ->where('employee_id', $employee->id)
            ->where('date', '2026-09-29')
            ->firstOrFail();
        $this->assertSame(30, $yesterday->late_minutes);

        $this->actingAs($admin)
            ->put(route('schedule.update'), [
                'start_time' => '10:00',
                'end_time' => '18:00',
                'grace_minutes' => 15,
                'break_start' => '13:00',
                'break_end' => '14:00',
                'work_days' => ['1', '2', '3', '4', '5'],
            ])
            ->assertRedirect(route('schedule.edit'));

        $schedules = WorkSchedule::withoutGlobalScopes()
            ->where('organization_id', $ctx['organization']->id)
            ->get();
        $this->assertSame(2, $schedules->count());

        $old = $schedules->firstWhere('is_default', false);
        $this->assertSame('2026-09-29', $old->effective_to?->toDateString());

        $new = $schedules->firstWhere('is_default', true);
        $this->assertSame('2026-09-30', $new->effective_from?->toDateString());
        $this->assertSame('10:00:00', $new->start_time);
        $this->assertNull($new->effective_to);

        // Re-deriving yesterday still resolves the old version → history unchanged.
        $service->deriveDaily($employee, '2026-09-29');
        $yesterday = $yesterday->fresh();
        $this->assertSame(30, $yesterday->late_minutes);

        // Today uses the new 10:00 schedule → 09:30 is within grace.
        $service->recordManual(
            $employee,
            AttendanceEvent::CHECK_IN,
            Carbon::parse('2026-09-30 09:30:00'),
            'seed',
            $admin->id,
        );
        $today = DailyAttendance::withoutGlobalScopes()
            ->where('employee_id', $employee->id)
            ->where('date', '2026-09-30')
            ->firstOrFail();
        $this->assertSame(0, $today->late_minutes);
    }

    public function test_unchanged_schedule_update_is_noop(): void
    {
        $ctx = $this->makeOrganization('SchedNoop');
        app(WorkforceDefaults::class)->apply($ctx['organization']);
        $admin = $this->makeUser($ctx['organization'], 'company_admin', $ctx['roles']);

        $this->actingAs($admin)
            ->put(route('schedule.update'), [
                'start_time' => '09:00',
                'end_time' => '18:00',
                'grace_minutes' => 15,
                'break_start' => '13:00',
                'break_end' => '14:00',
                'work_days' => ['1', '2', '3', '4', '5'],
            ])
            ->assertRedirect(route('schedule.edit'))
            ->assertSessionHas('status', 'Working hours unchanged.');

        $this->assertSame(
            1,
            WorkSchedule::withoutGlobalScopes()
                ->where('organization_id', $ctx['organization']->id)
                ->count(),
        );
    }

    public function test_settings_update_persists_debounce_seconds(): void
    {
        $ctx = $this->makeOrganization('DebSet');
        app(WorkforceDefaults::class)->apply($ctx['organization']);
        $admin = $this->makeUser($ctx['organization'], 'company_admin', $ctx['roles']);

        $this->actingAs($admin)
            ->put(route('settings.update'), [
                'name' => $ctx['organization']->name,
                'timezone' => 'UTC',
                'debounce_seconds' => 25,
                'time_format' => '12h',
            ])
            ->assertRedirect(route('settings.edit'));

        $this->assertSame(25, $ctx['organization']->fresh()->debounceSeconds());
        $this->assertSame('12h', $ctx['organization']->fresh()->timeFormat());

        $this->actingAs($admin)
            ->put(route('settings.update'), [
                'name' => $ctx['organization']->name,
                'timezone' => 'UTC',
                'debounce_seconds' => 2,
                'time_format' => '12h',
            ])
            ->assertSessionHasErrors('debounce_seconds');

        $this->assertSame(25, $ctx['organization']->fresh()->debounceSeconds());

        $this->actingAs($admin)
            ->put(route('settings.update'), [
                'name' => $ctx['organization']->name,
                'timezone' => 'UTC',
                'debounce_seconds' => 25,
                'time_format' => '36h',
            ])
            ->assertSessionHasErrors('time_format');

        $this->assertSame('12h', $ctx['organization']->fresh()->timeFormat());
    }
}
