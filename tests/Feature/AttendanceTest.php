<?php

namespace Tests\Feature;

use App\Models\AttendanceEvent;
use App\Models\AttendanceTerminal;
use App\Models\DailyAttendance;
use App\Models\Employee;
use App\Models\NfcCard;
use App\Models\User;
use App\Services\AttendanceService;
use App\Services\WorkforceDefaults;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesOrganizations;
use Tests\TestCase;

class AttendanceTest extends TestCase
{
    use CreatesOrganizations;
    use RefreshDatabase;

    private function makeTerminal($org, string $key = 'test-key'): AttendanceTerminal
    {
        $terminal = new AttendanceTerminal([
            'name' => 'TERM-TEST-001',
            'location' => 'Dhaka',
            'status' => 'active',
            'key_hash' => hash('sha256', $key),
        ]);
        $terminal->organization()->associate($org);
        $terminal->save();

        return $terminal;
    }

    private function makePunchableEmployee($org): array
    {
        $employee = $this->makeEmployee($org, 'EMP-P1', 'attend@test.com');
        $card = new NfcCard(['card_token' => 'tok-punch-1', 'status' => 'active']);
        $card->organization()->associate($org);
        $card->save();
        $card->employee()->associate($employee);
        $card->save();

        return [$employee, $card];
    }

    public function test_nfc_punch_requires_terminal_key(): void
    {
        $org = $this->makeOrganization('PunchCo');
        [$employee, $card] = $this->makePunchableEmployee($org);

        $response = $this->postJson('/api/attendance/nfc/punch', ['card_token' => $card->card_token]);
        $response->assertStatus(401);
    }

    public function test_nfc_punch_creates_check_in_and_check_out(): void
    {
        $org = $this->makeOrganization('PunchCo2');
        $terminal = $this->makeTerminal($org, 'key-abc');
        [$employee, $card] = $this->makePunchableEmployee($org);

        $this->withHeaders(['X-Terminal-Key' => 'key-abc'])
            ->postJson('/api/attendance/nfc/punch', ['card_token' => $card->card_token])
            ->assertOk()
            ->assertJsonPath('event', 'CHECK_IN');

        $this->assertDatabaseHas('attendance_events', [
            'organization_id' => $org->id,
            'employee_id' => $employee->id,
            'type' => AttendanceEvent::CHECK_IN,
        ]);

        // Immediate second punch is debounced.
        $this->withHeaders(['X-Terminal-Key' => 'key-abc'])
            ->postJson('/api/attendance/nfc/punch', ['card_token' => $card->card_token])
            ->assertOk()
            ->assertJsonPath('event', 'DEBOUNCED');

        // Travel back so the debounce window passes, then punch out.
        $this->travel(120)->seconds();

        $this->withHeaders(['X-Terminal-Key' => 'key-abc'])
            ->postJson('/api/attendance/nfc/punch', ['card_token' => $card->card_token])
            ->assertOk()
            ->assertJsonPath('event', 'CHECK_OUT');
    }

    public function test_punch_with_unknown_card_rejected(): void
    {
        $org = $this->makeOrganization('PunchCo3');
        $this->makeTerminal($org, 'key-xyz');

        $this->withHeaders(['X-Terminal-Key' => 'key-xyz'])
            ->postJson('/api/attendance/nfc/punch', ['card_token' => 'tok-none'])
            ->assertStatus(404);
    }

    public function test_punch_with_wrong_terminal_key_rejected(): void
    {
        $org = $this->makeOrganization('PunchCo4');
        $this->makeTerminal($org, 'key-real');
        [, $card] = $this->makePunchableEmployee($org);

        $this->withHeaders(['X-Terminal-Key' => 'key-wrong'])
            ->postJson('/api/attendance/nfc/punch', ['card_token' => $card->card_token])
            ->assertStatus(401);
    }

    public function test_punch_on_revoked_terminal_rejected(): void
    {
        $org = $this->makeOrganization('PunchCo5');
        $terminal = $this->makeTerminal($org, 'key-rev');
        $terminal->update(['status' => 'revoked']);
        [, $card] = $this->makePunchableEmployee($org);

        $this->withHeaders(['X-Terminal-Key' => 'key-rev'])
            ->postJson('/api/attendance/nfc/punch', ['card_token' => $card->card_token])
            ->assertStatus(401);
    }

    public function test_manual_correction_creates_event(): void
    {
        $org = $this->makeOrganization('PunchCo6');
        $owner = $org->users()->where('email', $this->ownerEmail())->firstOrFail();
        $employee = $this->makeEmployee($org, 'EMP-M1', 'manual@test.com');

        $this->actingAs($owner)
            ->post(route('attendance.store'), [
                'employee_id' => $employee->id,
                'type' => AttendanceEvent::CHECK_IN,
                'occurred_at' => now()->format('Y-m-d H:i'),
                'reason' => 'Forgot to punch',
            ])
            ->assertRedirect(route('attendance.index'));

        $this->assertDatabaseHas('attendance_events', [
            'organization_id' => $org->id,
            'employee_id' => $employee->id,
            'type' => AttendanceEvent::CHECK_IN,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'organization_id' => $org->id,
            'action' => 'attendance.store',
        ]);
    }

    public function test_attendance_index_is_tenant_isolated(): void
    {
        $orgA = $this->makeOrganization('AttCoA');
        $orgB = $this->makeOrganization('AttCoB');
        $ownerA = $orgA->users()->where('email', $this->ownerEmail())->firstOrFail();

        $employeeA = $this->makeEmployee($orgA, 'EMP-A', 'a@att.com');
        $service = app(AttendanceService::class);
        $service->recordManual($orgA, $employeeA, AttendanceEvent::CHECK_IN, now(), 'seed', $ownerA);

        $this->actingAs($ownerA)
            ->get(route('attendance.index'))
            ->assertOk()
            ->assertSee($employeeA->employee_code);

        // Org B owner sees none of Org A's data.
        $ownerB = $orgB->users()->where('email', $this->ownerEmail())->firstOrFail();
        $this->actingAs($ownerB)
            ->get(route('attendance.index'))
            ->assertOk()
            ->assertDontSee($employeeA->employee_code);
    }

    public function test_attendance_requires_permission(): void
    {
        $org = $this->makeOrganization('AttCoC');
        $employeeUser = $org->users()->where('email', $this->ownerEmail())->firstOrFail();
        $employeeUser->syncRoles(['employee']);

        $this->actingAs($employeeUser)
            ->get(route('attendance.index'))
            ->assertForbidden();
    }

    public function test_derive_daily_marks_present_for_in_range_punch(): void
    {
        $org = $this->makeOrganization('AttCoD');
        WorkforceDefaults::apply($org);

        $employee = $this->makeEmployee($org, 'EMP-D1', 'd@att.com');
        $today = now()->toDateString();

        app(AttendanceService::class)->recordManual($org, $employee, AttendanceEvent::CHECK_IN, now()->setTime(9, 5), 'seed');
        app(AttendanceService::class)->recordManual($org, $employee, AttendanceEvent::CHECK_OUT, now()->setTime(18, 10), 'seed');

        $daily = DailyAttendance::withoutGlobalScopes()
            ->where('organization_id', $org->id)
            ->where('employee_id', $employee->id)
            ->where('date', $today)
            ->first();

        $this->assertNotNull($daily);
        $this->assertSame('PRESENT', $daily->status);
    }
}
