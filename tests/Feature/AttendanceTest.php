<?php

namespace Tests\Feature;

use App\Models\AttendanceEvent;
use App\Models\AttendanceTerminal;
use App\Models\DailyAttendance;
use App\Models\NfcCard;
use App\Services\AttendanceService;
use App\Services\WorkforceDefaults;
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
}
