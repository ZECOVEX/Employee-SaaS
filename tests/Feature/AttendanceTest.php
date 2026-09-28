<?php

namespace Tests\Feature;

use App\Models\AttendanceEvent;
use App\Models\AttendanceTerminal;
use App\Models\AuditLog;
use App\Models\DailyAttendance;
use App\Models\NfcCard;
use App\Services\AttendanceService;
use App\Services\WorkforceDefaults;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesOrganizations;
use Tests\TestCase;

class AttendanceTest extends TestCase
{
    use CreatesOrganizations;
    use RefreshDatabase;

    private function makeTerminal(array $ctx, string $key = 'test-key'): AttendanceTerminal
    {
        return AttendanceTerminal::create([
            'organization_id' => $ctx['organization']->id,
            'name' => 'TERM-TEST-001',
            'location' => 'Dhaka',
            'status' => 'active',
            'key_hash' => hash('sha256', $key),
        ]);
    }

    private function makePunchableEmployee(array $ctx): array
    {
        $user = $this->makeUser($ctx['organization'], 'employee', $ctx['roles']);
        $employee = $this->makeEmployee($ctx['organization'], $user, 'EMP-P1');

        $card = NfcCard::create([
            'organization_id' => $ctx['organization']->id,
            'card_token' => 'tok-punch-1',
            'status' => 'active',
            'employee_id' => $employee->id,
        ]);

        return [$employee, $card];
    }

    public function test_nfc_punch_requires_terminal_key(): void
    {
        $ctx = $this->makeOrganization('PunchCo');
        [, $card] = $this->makePunchableEmployee($ctx);

        $this->postJson('/api/attendance/nfc/punch', ['card_token' => $card->card_token])
            ->assertStatus(401);
    }

    public function test_nfc_punch_creates_check_in_and_check_out(): void
    {
        $ctx = $this->makeOrganization('PunchCo2');
        $this->makeTerminal($ctx, 'key-abc');
        [, $card] = $this->makePunchableEmployee($ctx);

        $this->withHeaders(['X-Terminal-Key' => 'key-abc'])
            ->postJson('/api/attendance/nfc/punch', ['card_token' => $card->card_token])
            ->assertOk()
            ->assertJsonPath('action', 'check_in')
            ->assertJsonPath('event_type', AttendanceEvent::CHECK_IN);

        $this->assertDatabaseHas('attendance_events', [
            'organization_id' => $ctx['organization']->id,
            'event_type' => AttendanceEvent::CHECK_IN,
        ]);

        // Immediate second punch is debounced (no accidental check-out).
        $this->withHeaders(['X-Terminal-Key' => 'key-abc'])
            ->postJson('/api/attendance/nfc/punch', ['card_token' => $card->card_token])
            ->assertOk()
            ->assertJsonPath('action', 'duplicate');

        $this->assertSame(
            1,
            AttendanceEvent::withoutGlobalScopes()
                ->where('organization_id', $ctx['organization']->id)
                ->count(),
        );

        // After the debounce window a punch toggles to check-out.
        $this->travel(120)->seconds();

        $this->withHeaders(['X-Terminal-Key' => 'key-abc'])
            ->postJson('/api/attendance/nfc/punch', ['card_token' => $card->card_token])
            ->assertOk()
            ->assertJsonPath('action', 'check_out')
            ->assertJsonPath('event_type', AttendanceEvent::CHECK_OUT);
    }

    public function test_punch_with_unknown_card_rejected(): void
    {
        $ctx = $this->makeOrganization('PunchCo3');
        $this->makeTerminal($ctx, 'key-xyz');

        $this->withHeaders(['X-Terminal-Key' => 'key-xyz'])
            ->postJson('/api/attendance/nfc/punch', ['card_token' => 'tok-none'])
            ->assertStatus(422);
    }

    public function test_punch_with_inactive_card_rejected(): void
    {
        $ctx = $this->makeOrganization('PunchCo3b');
        $this->makeTerminal($ctx, 'key-in');
        $user = $this->makeUser($ctx['organization'], 'employee', $ctx['roles']);
        $employee = $this->makeEmployee($ctx['organization'], $user, 'EMP-P9');
        NfcCard::create([
            'organization_id' => $ctx['organization']->id,
            'card_token' => 'tok-blocked',
            'status' => 'blocked',
            'employee_id' => $employee->id,
        ]);

        $this->withHeaders(['X-Terminal-Key' => 'key-in'])
            ->postJson('/api/attendance/nfc/punch', ['card_token' => 'tok-blocked'])
            ->assertStatus(422);
    }

    public function test_punch_with_wrong_terminal_key_rejected(): void
    {
        $ctx = $this->makeOrganization('PunchCo4');
        $this->makeTerminal($ctx, 'key-real');
        [, $card] = $this->makePunchableEmployee($ctx);

        $this->withHeaders(['X-Terminal-Key' => 'key-wrong'])
            ->postJson('/api/attendance/nfc/punch', ['card_token' => $card->card_token])
            ->assertStatus(401);
    }

    public function test_punch_on_revoked_terminal_rejected(): void
    {
        $ctx = $this->makeOrganization('PunchCo5');
        $terminal = $this->makeTerminal($ctx, 'key-rev');
        $terminal->update(['status' => 'revoked']);
        [, $card] = $this->makePunchableEmployee($ctx);

        $this->withHeaders(['X-Terminal-Key' => 'key-rev'])
            ->postJson('/api/attendance/nfc/punch', ['card_token' => $card->card_token])
            ->assertStatus(401);
    }

    public function test_manual_correction_creates_event_and_audit_log(): void
    {
        $ctx = $this->makeOrganization('PunchCo6');
        $admin = $this->makeUser($ctx['organization'], 'company_admin', $ctx['roles']);
        $employee = $this->makeEmployee($ctx['organization'], $admin, 'EMP-M1');
        $when = now()->format('Y-m-d H:i');

        $this->actingAs($admin)
            ->post(route('attendance.store'), [
                'employee_id' => $employee->id,
                'event_type' => AttendanceEvent::MANUAL_IN,
                'occurred_at' => $when,
                'notes' => 'Forgot to punch',
            ])
            ->assertRedirect(route('attendance.index', ['date' => now()->toDateString()]));

        $this->assertDatabaseHas('attendance_events', [
            'organization_id' => $ctx['organization']->id,
            'employee_id' => $employee->id,
            'event_type' => AttendanceEvent::MANUAL_IN,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'organization_id' => $ctx['organization']->id,
            'action' => 'attendance.corrected',
        ]);
    }

    public function test_attendance_index_is_tenant_isolated(): void
    {
        $ctxA = $this->makeOrganization('AttCoA');
        $ctxB = $this->makeOrganization('AttCoB');
        $adminA = $this->makeUser($ctxA['organization'], 'company_admin', $ctxA['roles']);
        $employeeA = $this->makeEmployee($ctxA['organization'], $adminA, 'EMP-A');

        app(AttendanceService::class)->recordManual(
            $employeeA,
            AttendanceEvent::CHECK_IN,
            now()->setTime(9, 5),
            'seed',
            $adminA->id,
        );

        $this->actingAs($adminA)
            ->get(route('attendance.index'))
            ->assertOk()
            ->assertSee('EMP-A');

        $adminB = $this->makeUser($ctxB['organization'], 'company_admin', $ctxB['roles']);
        $this->actingAs($adminB)
            ->get(route('attendance.index'))
            ->assertOk()
            ->assertDontSee('EMP-A');
    }

    public function test_attendance_requires_permission(): void
    {
        $ctx = $this->makeOrganization('AttCoC');
        $employeeUser = $this->makeUser($ctx['organization'], 'employee', $ctx['roles']);

        $this->actingAs($employeeUser)
            ->get(route('attendance.index'))
            ->assertForbidden();
    }

    public function test_manual_correction_requires_permission(): void
    {
        $ctx = $this->makeOrganization('AttCoD');
        $employeeUser = $this->makeUser($ctx['organization'], 'employee', $ctx['roles']);

        $this->actingAs($employeeUser)
            ->post(route('attendance.store'), [
                'employee_id' => 1,
                'event_type' => AttendanceEvent::MANUAL_IN,
                'occurred_at' => now()->format('Y-m-d H:i'),
                'notes' => 'x',
            ])
            ->assertForbidden();
    }

    public function test_derive_daily_marks_present_for_in_range_punch(): void
    {
        $ctx = $this->makeOrganization('AttCoE');
        app(WorkforceDefaults::class)->apply($ctx['organization']);

        $user = $this->makeUser($ctx['organization'], 'employee', $ctx['roles']);
        $employee = $this->makeEmployee($ctx['organization'], $user, 'EMP-D1');
        $today = now()->toDateString();

        $service = app(AttendanceService::class);
        $service->recordManual($employee, AttendanceEvent::CHECK_IN, now()->setTime(9, 5), 'seed', $user->id);
        $service->recordManual($employee, AttendanceEvent::CHECK_OUT, now()->setTime(18, 10), 'seed', $user->id);

        $daily = DailyAttendance::withoutGlobalScopes()
            ->where('organization_id', $ctx['organization']->id)
            ->where('employee_id', $employee->id)
            ->where('date', $today)
            ->first();

        $this->assertNotNull($daily);
        $this->assertSame('PRESENT', $daily->status);
        $this->assertSame(now()->setTime(9, 5)->toDateTimeString(), $daily->first_check_in?->toDateTimeString());
        $this->assertSame(now()->setTime(18, 10)->toDateTimeString(), $daily->last_check_out?->toDateTimeString());
    }

    public function test_daily_total_sums_segments_instead_of_first_to_last_span(): void
    {
        $ctx = $this->makeOrganization('SegCo');
        app(WorkforceDefaults::class)->apply($ctx['organization']);
        $user = $this->makeUser($ctx['organization'], 'employee', $ctx['roles']);
        $employee = $this->makeEmployee($ctx['organization'], $user, 'EMP-S1');

        $service = app(AttendanceService::class);
        // 09:00 in → 10:00 out (errand) → 11:00 in → 18:00 out.
        $service->recordManual($employee, AttendanceEvent::CHECK_IN, now()->setTime(9, 0), 'seed', $user->id);
        $service->recordManual($employee, AttendanceEvent::CHECK_OUT, now()->setTime(10, 0), 'seed', $user->id);
        $service->recordManual($employee, AttendanceEvent::CHECK_IN, now()->setTime(11, 0), 'seed', $user->id);
        $service->recordManual($employee, AttendanceEvent::CHECK_OUT, now()->setTime(18, 0), 'seed', $user->id);

        $daily = DailyAttendance::withoutGlobalScopes()
            ->where('organization_id', $ctx['organization']->id)
            ->where('employee_id', $employee->id)
            ->where('date', now()->toDateString())
            ->firstOrFail();

        $this->assertSame(2, $daily->segment_count);
        // 60 + 420 = 480 segment minutes; the configured 13:00–14:00 break sits inside
        // segment 2 (no gap overlaps it), so the full break is deducted: 420.
        // The 10:00–11:00 errand is NOT counted — a first→last span would claim 480.
        $this->assertSame(420, $daily->total_work_minutes);
        $this->assertNull($daily->review_flag);
    }

    public function test_unreturned_exit_marks_half_day_and_review_flag(): void
    {
        $this->travelTo(Carbon::parse('2026-09-30 17:00:00')); // Wednesday (workday)

        $ctx = $this->makeOrganization('FlagCo');
        app(WorkforceDefaults::class)->apply($ctx['organization']);
        $user = $this->makeUser($ctx['organization'], 'employee', $ctx['roles']);
        $employee = $this->makeEmployee($ctx['organization'], $user, 'EMP-F1');

        $service = app(AttendanceService::class);
        $service->recordManual($employee, AttendanceEvent::CHECK_IN, now()->setTime(9, 0), 'seed', $user->id);
        $service->recordManual($employee, AttendanceEvent::CHECK_OUT, now()->setTime(12, 30), 'seed', $user->id);

        $daily = DailyAttendance::withoutGlobalScopes()
            ->where('organization_id', $ctx['organization']->id)
            ->where('employee_id', $employee->id)
            ->where('date', '2026-09-30')
            ->firstOrFail();

        $this->assertSame('HALF_DAY', $daily->status);
        $this->assertSame('possible_missed_checkin', $daily->review_flag);
        $this->assertSame(1, $daily->segment_count);
        // 210 segment minutes − 60 minute break (no gaps) = 150 (< half of 480 expected).
        $this->assertSame(150, $daily->total_work_minutes);
    }

    public function test_excessive_segments_are_flagged_for_review(): void
    {
        $this->travelTo(Carbon::parse('2026-09-30 17:00:00')); // Wednesday (workday)

        $ctx = $this->makeOrganization('AnomCo');
        app(WorkforceDefaults::class)->apply($ctx['organization']);
        $user = $this->makeUser($ctx['organization'], 'employee', $ctx['roles']);
        $employee = $this->makeEmployee($ctx['organization'], $user, 'EMP-A1');

        $service = app(AttendanceService::class);
        // 15 quick in/out pairs (MAX_SEGMENTS = 15): 10 before lunch, 5 after, with the
        // gap 12:56→14:00 covering the whole break so nothing is deducted.
        $base = now()->setTime(9, 0);
        for ($i = 0; $i < 15; $i++) {
            $in = $i < 10
                ? $base->copy()->addMinutes($i * 24)
                : $base->copy()->setTime(14, 0)->addMinutes(($i - 10) * 24);
            $service->recordManual($employee, AttendanceEvent::CHECK_IN, $in, 'taps', $user->id);
            $service->recordManual($employee, AttendanceEvent::CHECK_OUT, $in->copy()->addMinutes(20), 'taps', $user->id);
        }

        $daily = DailyAttendance::withoutGlobalScopes()
            ->where('organization_id', $ctx['organization']->id)
            ->where('employee_id', $employee->id)
            ->where('date', '2026-09-30')
            ->firstOrFail();

        $this->assertSame(15, $daily->segment_count);
        $this->assertSame('excessive_segments', $daily->review_flag);
        $this->assertSame('PRESENT', $daily->status);
        $this->assertSame(300, $daily->total_work_minutes);
    }

    public function test_debounce_window_is_configurable_per_organization(): void
    {
        $ctx = $this->makeOrganization('DebCo');
        $this->makeTerminal($ctx, 'key-deb');
        [, $card] = $this->makePunchableEmployee($ctx);
        $ctx['organization']->update(['settings' => ['debounce_seconds' => 60]]);

        $this->withHeaders(['X-Terminal-Key' => 'key-deb'])
            ->postJson('/api/attendance/nfc/punch', ['card_token' => $card->card_token])
            ->assertOk()
            ->assertJsonPath('action', 'check_in');

        $this->travel(30)->seconds();

        $this->withHeaders(['X-Terminal-Key' => 'key-deb'])
            ->postJson('/api/attendance/nfc/punch', ['card_token' => $card->card_token])
            ->assertOk()
            ->assertJsonPath('action', 'duplicate');

        $this->travel(31)->seconds();

        $this->withHeaders(['X-Terminal-Key' => 'key-deb'])
            ->postJson('/api/attendance/nfc/punch', ['card_token' => $card->card_token])
            ->assertOk()
            ->assertJsonPath('action', 'check_out');
    }

    public function test_debounce_is_scoped_per_terminal(): void
    {
        $ctx = $this->makeOrganization('DebTermCo');
        $this->makeTerminal($ctx, 'key-t1');
        $terminalB = AttendanceTerminal::create([
            'organization_id' => $ctx['organization']->id,
            'name' => 'TERM-T2',
            'location' => 'Gate 2',
            'status' => 'active',
            'key_hash' => hash('sha256', 'key-t2'),
        ]);
        [, $card] = $this->makePunchableEmployee($ctx);

        $this->withHeaders(['X-Terminal-Key' => 'key-t1'])
            ->postJson('/api/attendance/nfc/punch', ['card_token' => $card->card_token])
            ->assertOk()
            ->assertJsonPath('action', 'check_in');

        // A different terminal has no prior tap from this employee, so it is not
        // debounced — the state machine flips to check-out (§72-A).
        $this->withHeaders(['X-Terminal-Key' => 'key-t2'])
            ->postJson('/api/attendance/nfc/punch', ['card_token' => $card->card_token])
            ->assertOk()
            ->assertJsonPath('action', 'check_out');

        $this->assertSame(
            2,
            AttendanceEvent::withoutGlobalScopes()
                ->where('organization_id', $ctx['organization']->id)
                ->count(),
        );
        $this->assertNotNull($terminalB->id);
    }

    public function test_v1_punch_endpoint_and_terminal_id_matching(): void
    {
        $ctx = $this->makeOrganization('V1Co');
        $terminal = $this->makeTerminal($ctx, 'key-v1');
        [, $card] = $this->makePunchableEmployee($ctx);

        $this->withHeaders(['X-Terminal-Key' => 'key-v1'])
            ->postJson('/api/v1/attendance/nfc/punch', [
                'card_token' => $card->card_token,
                'terminal_id' => $terminal->id,
            ])
            ->assertOk()
            ->assertJsonPath('action', 'check_in');

        $this->withHeaders(['X-Terminal-Key' => 'key-v1'])
            ->postJson('/api/v1/attendance/nfc/punch', [
                'card_token' => $card->card_token,
                'terminal_id' => 999999,
            ])
            ->assertForbidden();

        // Legacy path stays functional for already-provisioned terminals.
        $this->withHeaders(['X-Terminal-Key' => 'key-v1'])
            ->postJson('/api/attendance/nfc/punch', [
                'card_token' => $card->card_token,
                'terminal_id' => $terminal->id,
            ])
            ->assertOk();
    }

    public function test_employee_can_view_own_attendance_but_not_others(): void
    {
        $ctx = $this->makeOrganization('SelfCo');
        $employeeUser = $this->makeUser($ctx['organization'], 'employee', $ctx['roles']);
        $employee = $this->makeEmployee($ctx['organization'], $employeeUser, 'EMP-SELF');

        $otherUser = $this->makeUser($ctx['organization'], 'employee', $ctx['roles']);
        $other = $this->makeEmployee($ctx['organization'], $otherUser, 'EMP-OTH2');

        $this->actingAs($employeeUser)
            ->get(route('attendance.employee', $employee))
            ->assertOk()
            ->assertSee('My Attendance')
            ->assertSee('EMP-SELF');

        $this->actingAs($employeeUser)
            ->get(route('attendance.employee', $other))
            ->assertForbidden();

        $admin = $this->makeUser($ctx['organization'], 'company_admin', $ctx['roles']);
        $this->actingAs($admin)
            ->get(route('attendance.employee', $employee))
            ->assertOk();
    }

    public function test_admin_can_edit_an_entry_and_the_day_is_rederived(): void
    {
        $this->travelTo(Carbon::parse('2026-09-30 12:00:00')); // Wednesday

        $ctx = $this->makeOrganization('EditCo');
        app(WorkforceDefaults::class)->apply($ctx['organization']);
        $admin = $this->makeUser($ctx['organization'], 'company_admin', $ctx['roles']);
        $user = $this->makeUser($ctx['organization'], 'employee', $ctx['roles']);
        $employee = $this->makeEmployee($ctx['organization'], $user, 'EMP-E1');

        $result = app(AttendanceService::class)
            ->recordManual($employee, AttendanceEvent::CHECK_IN, now()->setTime(9, 5), 'seed', $admin->id);
        $event = $result['event'];

        $this->actingAs($admin)
            ->get(route('attendance.events.edit', $event))
            ->assertOk()
            ->assertSee('Edit Attendance Entry');

        $this->actingAs($admin)
            ->put(route('attendance.events.update', $event), [
                'event_type' => AttendanceEvent::MANUAL_IN,
                'occurred_at' => '2026-09-30T09:30',
                'notes' => 'Wrong tap time',
            ])
            ->assertRedirect(route('attendance.index', ['date' => '2026-09-30']))
            ->assertSessionHas('status');

        // The original is archived, never overwritten (§9).
        $event->refresh();
        $this->assertNotNull($event->superseded_by);
        $this->assertSame(AttendanceEvent::CHECK_IN, $event->event_type);
        $this->assertSame(now()->setTime(9, 5)->toDateTimeString(), $event->occurred_at->toDateTimeString());

        $replacement = AttendanceEvent::withoutGlobalScopes()->findOrFail($event->superseded_by);
        $this->assertSame(AttendanceEvent::MANUAL_IN, $replacement->event_type);
        $this->assertSame('Wrong tap time', $replacement->notes);
        $this->assertSame($admin->id, $replacement->created_by);

        // The day was re-derived from the active set only.
        $daily = DailyAttendance::withoutGlobalScopes()
            ->where('employee_id', $employee->id)
            ->where('date', '2026-09-30')
            ->firstOrFail();
        $this->assertSame(now()->setTime(9, 30)->toDateTimeString(), $daily->first_check_in?->toDateTimeString());
        $this->assertSame('LATE', $daily->status);
        $this->assertSame(30, $daily->late_minutes);

        $this->assertNotNull(
            AuditLog::withoutGlobalScopes()
                ->where('organization_id', $ctx['organization']->id)
                ->where('action', 'attendance.event_updated')
                ->where('actor_user_id', $admin->id)
                ->first(),
        );

        // The archived entry can no longer be edited again.
        $this->actingAs($admin)
            ->get(route('attendance.events.edit', $event))
            ->assertNotFound();
        $this->actingAs($admin)
            ->put(route('attendance.events.update', $event), [
                'event_type' => AttendanceEvent::MANUAL_IN,
                'occurred_at' => '2026-09-30T10:00',
                'notes' => 'nope',
            ])
            ->assertNotFound();
    }

    public function test_editing_an_entry_to_another_date_rederives_both_days(): void
    {
        $this->travelTo(Carbon::parse('2026-09-30 12:00:00')); // Wednesday

        $ctx = $this->makeOrganization('MoveCo');
        app(WorkforceDefaults::class)->apply($ctx['organization']);
        $admin = $this->makeUser($ctx['organization'], 'company_admin', $ctx['roles']);
        $user = $this->makeUser($ctx['organization'], 'employee', $ctx['roles']);
        $employee = $this->makeEmployee($ctx['organization'], $user, 'EMP-M1');

        $event = app(AttendanceService::class)
            ->recordManual($employee, AttendanceEvent::CHECK_IN, now()->setTime(9, 5), 'seed', $admin->id)['event'];

        $this->actingAs($admin)
            ->put(route('attendance.events.update', $event), [
                'event_type' => AttendanceEvent::CHECK_IN,
                'occurred_at' => '2026-09-29T09:05',
                'notes' => 'Entered on the wrong day',
            ])
            ->assertRedirect(route('attendance.index', ['date' => '2026-09-29']));

        // Yesterday (Tuesday) now holds the entry…
        $moved = DailyAttendance::withoutGlobalScopes()
            ->where('employee_id', $employee->id)
            ->where('date', '2026-09-29')
            ->firstOrFail();
        $this->assertSame('PRESENT', $moved->status);
        $this->assertSame(now()->subDay()->setTime(9, 5)->toDateTimeString(), $moved->first_check_in?->toDateTimeString());

        // …and today was recalculated without it.
        $today = DailyAttendance::withoutGlobalScopes()
            ->where('employee_id', $employee->id)
            ->where('date', '2026-09-30')
            ->firstOrFail();
        $this->assertSame('ABSENT', $today->status);
        $this->assertSame(0, $today->total_work_minutes);
        $this->assertNull($today->first_check_in);
    }

    public function test_admin_can_remove_an_entry_with_a_reason(): void
    {
        $this->travelTo(Carbon::parse('2026-09-30 12:00:00')); // Wednesday

        $ctx = $this->makeOrganization('DelCo');
        app(WorkforceDefaults::class)->apply($ctx['organization']);
        $admin = $this->makeUser($ctx['organization'], 'company_admin', $ctx['roles']);
        $user = $this->makeUser($ctx['organization'], 'employee', $ctx['roles']);
        $employee = $this->makeEmployee($ctx['organization'], $user, 'EMP-D1');

        $event = app(AttendanceService::class)
            ->recordManual($employee, AttendanceEvent::CHECK_IN, now()->setTime(9, 5), 'seed', $admin->id)['event'];

        // A reason is mandatory.
        $this->actingAs($admin)
            ->delete(route('attendance.events.destroy', $event))
            ->assertSessionHasErrors('reason');
        $this->assertNull($event->fresh()->deleted_at);

        $this->actingAs($admin)
            ->delete(route('attendance.events.destroy', $event), ['reason' => 'duplicate tap'])
            ->assertRedirect(route('attendance.index', ['date' => '2026-09-30']))
            ->assertSessionHas('status');

        $this->assertNotNull(AttendanceEvent::withoutGlobalScopes()->findOrFail($event->id)->deleted_at);

        $daily = DailyAttendance::withoutGlobalScopes()
            ->where('employee_id', $employee->id)
            ->where('date', '2026-09-30')
            ->firstOrFail();
        $this->assertSame('ABSENT', $daily->status);
        $this->assertSame(0, $daily->total_work_minutes);
        $this->assertNull($daily->first_check_in);

        $audit = AuditLog::withoutGlobalScopes()
            ->where('organization_id', $ctx['organization']->id)
            ->where('action', 'attendance.event_deleted')
            ->first();
        $this->assertNotNull($audit);
        $this->assertSame('duplicate tap', $audit->new_value['reason'] ?? null);
    }

    public function test_employee_cannot_edit_or_remove_attendance_entries(): void
    {
        $ctx = $this->makeOrganization('NoEditCo');
        app(WorkforceDefaults::class)->apply($ctx['organization']);
        $employeeUser = $this->makeUser($ctx['organization'], 'employee', $ctx['roles']);
        $employee = $this->makeEmployee($ctx['organization'], $employeeUser, 'EMP-NE1');
        $admin = $this->makeUser($ctx['organization'], 'company_admin', $ctx['roles']);

        $event = app(AttendanceService::class)
            ->recordManual($employee, AttendanceEvent::CHECK_IN, now()->setTime(9, 5), 'seed', $admin->id)['event'];

        $this->actingAs($employeeUser)
            ->get(route('attendance.events.edit', $event))
            ->assertForbidden();
        $this->actingAs($employeeUser)
            ->put(route('attendance.events.update', $event), [
                'event_type' => AttendanceEvent::MANUAL_IN,
                'occurred_at' => now()->format('Y-m-d\TH:i'),
                'notes' => 'self edit',
            ])
            ->assertForbidden();
        $this->actingAs($employeeUser)
            ->delete(route('attendance.events.destroy', $event), ['reason' => 'self delete'])
            ->assertForbidden();

        $this->assertNull($event->fresh()->deleted_at);
        $this->assertNull($event->fresh()->superseded_by);
    }

    public function test_entry_edit_routes_are_tenant_isolated(): void
    {
        $ctxA = $this->makeOrganization('IsoA');
        $ctxB = $this->makeOrganization('IsoB');
        $adminA = $this->makeUser($ctxA['organization'], 'company_admin', $ctxA['roles']);
        $adminB = $this->makeUser($ctxB['organization'], 'company_admin', $ctxB['roles']);
        $userA = $this->makeUser($ctxA['organization'], 'employee', $ctxA['roles']);
        $employeeA = $this->makeEmployee($ctxA['organization'], $userA, 'EMP-IA1');

        $event = app(AttendanceService::class)
            ->recordManual($employeeA, AttendanceEvent::CHECK_IN, now()->setTime(9, 5), 'seed', $adminA->id)['event'];

        $this->actingAs($adminB)
            ->get(route('attendance.events.edit', $event))
            ->assertNotFound();
        $this->actingAs($adminB)
            ->put(route('attendance.events.update', $event), [
                'event_type' => AttendanceEvent::MANUAL_IN,
                'occurred_at' => now()->format('Y-m-d\TH:i'),
                'notes' => 'cross tenant',
            ])
            ->assertNotFound();
        $this->actingAs($adminB)
            ->delete(route('attendance.events.destroy', $event), ['reason' => 'cross tenant'])
            ->assertNotFound();

        $this->assertNull($event->fresh()->deleted_at);
        $this->assertNull($event->fresh()->superseded_by);
    }

    public function test_attendance_times_follow_admin_time_format(): void
    {
        $this->travelTo(Carbon::parse('2026-09-30 17:00:00')); // Wednesday

        $ctx = $this->makeOrganization('TFormat');
        app(WorkforceDefaults::class)->apply($ctx['organization']);
        $admin = $this->makeUser($ctx['organization'], 'company_admin', $ctx['roles']);
        $user = $this->makeUser($ctx['organization'], 'employee', $ctx['roles']);
        $employee = $this->makeEmployee($ctx['organization'], $user, 'EMP-TF');

        app(AttendanceService::class)
            ->recordManual($employee, AttendanceEvent::CHECK_IN, now()->setTime(16, 0), 'seed', $admin->id);

        // Default: international 24-hour clock.
        $this->actingAs($admin)
            ->get(route('attendance.index', ['date' => '2026-09-30']))
            ->assertOk()
            ->assertSee('16:00');

        // Admin switches the org to the 12-hour (am/pm) format.
        $ctx['organization']->update(['settings' => ['time_format' => '12h']]);
        $admin->unsetRelation('organization'); // shared test instance would serve a stale relation

        $this->actingAs($admin)
            ->get(route('attendance.index', ['date' => '2026-09-30']))
            ->assertOk()
            ->assertSee('4:00 PM')
            ->assertDontSee('16:00');

        // Employee history follows the same setting.
        $this->actingAs($admin)
            ->get(route('attendance.employee', $employee))
            ->assertOk()
            ->assertSee('4:00 PM');
    }

    public function test_attendance_is_not_time_shifted_for_non_utc_organizations(): void
    {
        $this->travelTo(Carbon::parse('2026-09-30 10:30:00')); // = 16:30 in Dhaka

        $ctx = $this->makeOrganization('DhakaCo');
        $ctx['organization']->update(['timezone' => 'Asia/Dhaka']);
        app(WorkforceDefaults::class)->apply($ctx['organization']);
        $admin = $this->makeUser($ctx['organization'], 'company_admin', $ctx['roles']);
        $user = $this->makeUser($ctx['organization'], 'employee', $ctx['roles']);
        $employee = $this->makeEmployee($ctx['organization'], $user, 'EMP-DK');

        $this->actingAs($admin)
            ->post(route('attendance.store'), [
                'employee_id' => $employee->id,
                'event_type' => AttendanceEvent::MANUAL_IN,
                'occurred_at' => '2026-09-30 10:00',
                'notes' => 'Admin entered 10 o\'clock.',
            ])
            ->assertRedirect(route('attendance.index', ['date' => '2026-09-30']));

        $daily = DailyAttendance::withoutGlobalScopes()
            ->where('employee_id', $employee->id)
            ->where('date', '2026-09-30')
            ->firstOrFail();

        // 10:00 vs the 09:00 schedule = 60 minutes late — not 420, which is what
        // an instant-based comparison produced for UTC+6 orgs (10 AM shown as 4 PM).
        $this->assertSame(60, $daily->late_minutes);
        $this->assertSame('LATE', $daily->status);
        $this->assertSame('2026-09-30 10:00:00', $daily->first_check_in->format('Y-m-d H:i:s'));

        $this->actingAs($admin)
            ->get(route('attendance.index', ['date' => '2026-09-30']))
            ->assertOk()
            ->assertSee('10:00')
            ->assertDontSee('16:00');
    }

    public function test_correct_attendance_page_renders_both_forms(): void
    {
        $ctx = $this->makeOrganization('CorrPage');
        $admin = $this->makeUser($ctx['organization'], 'company_admin', $ctx['roles']);

        $this->actingAs($admin)
            ->get(route('attendance.create'))
            ->assertOk()
            ->assertSee('Enter check-in')
            ->assertSee(route('attendance.inout'), false)
            ->assertSee('Add a single event')
            ->assertSee(route('attendance.store'), false);
    }

    public function test_admin_can_enter_check_in_and_check_out_together(): void
    {
        $this->travelTo(Carbon::parse('2026-09-30 18:00:00')); // Wednesday

        $ctx = $this->makeOrganization('InOutCo');
        app(WorkforceDefaults::class)->apply($ctx['organization']);
        $admin = $this->makeUser($ctx['organization'], 'company_admin', $ctx['roles']);
        $user = $this->makeUser($ctx['organization'], 'employee', $ctx['roles']);
        $employee = $this->makeEmployee($ctx['organization'], $user, 'EMP-IO1');

        $this->actingAs($admin)
            ->post(route('attendance.inout'), [
                'inout_employee_id' => $employee->id,
                'inout_date' => '2026-09-30',
                'inout_check_in' => '09:00',
                'inout_check_out' => '17:00',
                'inout_notes' => 'Badge was not working.',
            ])
            ->assertRedirect(route('attendance.index', ['date' => '2026-09-30']))
            ->assertSessionHas('status');

        $daily = DailyAttendance::withoutGlobalScopes()
            ->where('employee_id', $employee->id)
            ->where('date', '2026-09-30')
            ->firstOrFail();

        $this->assertSame('PRESENT', $daily->status);
        $this->assertSame('2026-09-30 09:00:00', $daily->first_check_in->format('Y-m-d H:i:s'));
        $this->assertSame('2026-09-30 17:00:00', $daily->last_check_out->format('Y-m-d H:i:s'));
        // 09:00–17:00 (480) minus the configured 60-minute break.
        $this->assertSame(420, $daily->total_work_minutes);
        $this->assertSame(60, $daily->early_leave_minutes);
        $this->assertSame(1, $daily->segment_count);
        $this->assertSame(0, $daily->late_minutes);

        $this->assertSame(2, AttendanceEvent::where('employee_id', $employee->id)->count());
        $this->assertSame(
            2,
            AuditLog::withoutGlobalScopes()
                ->where('organization_id', $ctx['organization']->id)
                ->where('action', 'attendance.corrected')
                ->count(),
        );
    }

    public function test_inout_entry_requires_at_least_one_time(): void
    {
        $ctx = $this->makeOrganization('InOutReq');
        $admin = $this->makeUser($ctx['organization'], 'company_admin', $ctx['roles']);
        $user = $this->makeUser($ctx['organization'], 'employee', $ctx['roles']);
        $employee = $this->makeEmployee($ctx['organization'], $user, 'EMP-IO2');

        $this->actingAs($admin)
            ->post(route('attendance.inout'), [
                'inout_employee_id' => $employee->id,
                'inout_date' => '2026-09-30',
                'inout_notes' => 'Both times missing.',
            ])
            ->assertSessionHasErrors(['inout_check_in', 'inout_check_out']);
    }

    public function test_inout_entry_rejects_check_out_before_check_in(): void
    {
        $ctx = $this->makeOrganization('InOutOrder');
        $admin = $this->makeUser($ctx['organization'], 'company_admin', $ctx['roles']);
        $user = $this->makeUser($ctx['organization'], 'employee', $ctx['roles']);
        $employee = $this->makeEmployee($ctx['organization'], $user, 'EMP-IO3');

        $this->actingAs($admin)
            ->post(route('attendance.inout'), [
                'inout_employee_id' => $employee->id,
                'inout_date' => '2026-09-30',
                'inout_check_in' => '17:00',
                'inout_check_out' => '09:00',
                'inout_notes' => 'Swapped by mistake.',
            ])
            ->assertSessionHasErrors('inout_check_out');

        $this->assertSame(0, AttendanceEvent::withoutGlobalScopes()->count());
    }

    public function test_employee_cannot_enter_inout_times(): void
    {
        $ctx = $this->makeOrganization('InOutNo');
        $employeeUser = $this->makeUser($ctx['organization'], 'employee', $ctx['roles']);
        $employee = $this->makeEmployee($ctx['organization'], $employeeUser, 'EMP-IO4');

        $this->actingAs($employeeUser)
            ->post(route('attendance.inout'), [
                'inout_employee_id' => $employee->id,
                'inout_date' => '2026-09-30',
                'inout_check_in' => '09:00',
                'inout_notes' => 'self entry',
            ])
            ->assertForbidden();

        $this->assertSame(0, AttendanceEvent::withoutGlobalScopes()->count());
    }
}
