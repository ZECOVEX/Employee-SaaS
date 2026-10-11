<?php

namespace Tests\Feature;

use App\Models\AttendanceTerminal;
use App\Models\AuditLog;
use App\Models\NfcCard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesOrganizations;
use Tests\TestCase;

class NfcTerminalTest extends TestCase
{
    use CreatesOrganizations;
    use RefreshDatabase;

    public function test_admin_can_issue_cards_and_sees_plain_tokens_once(): void
    {
        $ctx = $this->makeOrganization('NfcCo1');
        $admin = $this->makeUser($ctx['organization'], 'company_admin', $ctx['roles']);

        $this->actingAs($admin)
            ->post(route('nfc-cards.store'), ['count' => 3])
            ->assertRedirect(route('nfc-cards.index'));

        $status = session('status');
        $this->assertIsString($status);
        // The flash (with plaintext tokens) is shown once, then gone.
        $this->assertStringContainsString('card(s) created', $status);

        $this->assertSame(3, NfcCard::withoutGlobalScopes()
            ->where('organization_id', $ctx['organization']->id)
            ->where('status', 'available')
            ->count());

        $tokens = Str::matchAll('/NFC-[A-Z0-9]+/', $status);
        $this->assertCount(3, $tokens);

        // First load consumes the flash; the token list itself never re-shows them.
        $this->actingAs($admin)->get(route('nfc-cards.index'))->assertOk();
        $this->actingAs($admin)->get(route('nfc-cards.index'))
            ->assertOk()
            ->assertDontSee('3 card(s) created');
    }

    public function test_assign_block_revoke_flow(): void
    {
        $ctx = $this->makeOrganization('NfcCo2');
        $admin = $this->makeUser($ctx['organization'], 'company_admin', $ctx['roles']);
        $user = $this->makeUser($ctx['organization'], 'employee', $ctx['roles']);
        $employee = $this->makeEmployee($ctx['organization'], $user, 'EMP-N1');

        $card = NfcCard::create([
            'organization_id' => $ctx['organization']->id,
            'card_token' => 'tok-flow',
            'status' => 'available',
        ]);

        $this->actingAs($admin)
            ->post(route('nfc-cards.assign', $card), ['employee_id' => $employee->id])
            ->assertRedirect();
        $this->assertSame('active', $card->fresh()->status);
        $this->assertSame($employee->id, $card->fresh()->employee_id);

        $this->actingAs($admin)
            ->post(route('nfc-cards.unassign', $card))
            ->assertRedirect();
        $this->assertSame('available', $card->fresh()->status);
        $this->assertNull($card->fresh()->employee_id);

        $this->actingAs($admin)
            ->post(route('nfc-cards.assign', $card), ['employee_id' => $employee->id])
            ->assertRedirect();

        $this->actingAs($admin)
            ->post(route('nfc-cards.block', $card))
            ->assertRedirect();
        $this->assertSame('blocked', $card->fresh()->status);

        $this->actingAs($admin)
            ->post(route('nfc-cards.revoke', $card))
            ->assertRedirect();
        $this->assertSame('revoked', $card->fresh()->status);
    }

    public function test_assign_rejects_employee_from_another_organization(): void
    {
        $ctxA = $this->makeOrganization('NfcCo3');
        $ctxB = $this->makeOrganization('NfcCo3b');
        $adminA = $this->makeUser($ctxA['organization'], 'company_admin', $ctxA['roles']);
        $userB = $this->makeUser($ctxB['organization'], 'employee', $ctxB['roles']);
        $employeeB = $this->makeEmployee($ctxB['organization'], $userB, 'EMP-XB');

        $card = NfcCard::create([
            'organization_id' => $ctxA['organization']->id,
            'card_token' => 'tok-a',
            'status' => 'available',
        ]);

        $this->actingAs($adminA)
            ->post(route('nfc-cards.assign', $card), ['employee_id' => $employeeB->id])
            ->assertSessionHasErrors('employee_id');

        $this->assertSame('available', $card->fresh()->status);
    }

    public function test_employee_without_permission_cannot_issue_cards(): void
    {
        $ctx = $this->makeOrganization('NfcCo4');
        $employeeUser = $this->makeUser($ctx['organization'], 'employee', $ctx['roles']);

        $this->actingAs($employeeUser)
            ->post(route('nfc-cards.store'), ['count' => 1])
            ->assertForbidden();
    }

    public function test_employee_without_permission_cannot_view_cards(): void
    {
        $ctx = $this->makeOrganization('NfcCo5');
        $employeeUser = $this->makeUser($ctx['organization'], 'employee', $ctx['roles']);

        $this->actingAs($employeeUser)
            ->get(route('nfc-cards.index'))
            ->assertForbidden();
    }

    public function test_terminal_creation_returns_key_once_and_revokes(): void
    {
        $ctx = $this->makeOrganization('TermCo1');
        $admin = $this->makeUser($ctx['organization'], 'company_admin', $ctx['roles']);

        $this->actingAs($admin)
            ->post(route('terminals.store'), [
                'name' => 'TERM-X',
                'location' => 'Dhaka',
            ])
            ->assertRedirect(route('terminals.index'));

        $status = session('status');
        $this->assertIsString($status);
        $plainKey = Str::after($status, 'again: ');
        $this->assertNotEmpty($plainKey);

        $terminal = AttendanceTerminal::withoutGlobalScopes()
            ->where('organization_id', $ctx['organization']->id)
            ->firstOrFail();
        $this->assertSame(hash('sha256', $plainKey), $terminal->key_hash);

        // First load consumes the flash, second load never shows the key again.
        $this->actingAs($admin)->get(route('terminals.index'))->assertOk();
        $this->actingAs($admin)->get(route('terminals.index'))
            ->assertOk()
            ->assertDontSee($plainKey);

        $this->actingAs($admin)
            ->delete(route('terminals.destroy', $terminal))
            ->assertRedirect();
        $this->assertSame('revoked', $terminal->fresh()->status);
    }

    public function test_terminal_key_authenticates_punch_endpoint(): void
    {
        $ctx = $this->makeOrganization('TermCo2');
        $admin = $this->makeUser($ctx['organization'], 'company_admin', $ctx['roles']);
        $user = $this->makeUser($ctx['organization'], 'employee', $ctx['roles']);
        $employee = $this->makeEmployee($ctx['organization'], $user, 'EMP-T1');

        $this->actingAs($admin)
            ->post(route('terminals.store'), ['name' => 'TERM-P', 'location' => null])
            ->assertRedirect();

        $plainKey = Str::after(session('status'), 'again: ');

        NfcCard::create([
            'organization_id' => $ctx['organization']->id,
            'card_token' => 'tok-term',
            'status' => 'active',
            'employee_id' => $employee->id,
        ]);

        $this->withHeaders(['X-Terminal-Key' => $plainKey])
            ->postJson('/api/attendance/nfc/punch', ['card_token' => 'tok-term'])
            ->assertOk()
            ->assertJsonPath('action', 'check_in');
    }

    public function test_terminal_routes_are_tenant_isolated(): void
    {
        $ctxA = $this->makeOrganization('TermCoA');
        $ctxB = $this->makeOrganization('TermCoB');
        $adminA = $this->makeUser($ctxA['organization'], 'company_admin', $ctxA['roles']);
        $adminB = $this->makeUser($ctxB['organization'], 'company_admin', $ctxB['roles']);

        $this->actingAs($adminA)
            ->post(route('terminals.store'), ['name' => 'TERM-A', 'location' => ''])
            ->assertRedirect();

        $terminalA = AttendanceTerminal::withoutGlobalScopes()
            ->where('organization_id', $ctxA['organization']->id)
            ->firstOrFail();

        $this->actingAs($adminB)
            ->get(route('terminals.index'))
            ->assertOk()
            ->assertDontSee('TERM-A');

        $this->actingAs($adminB)
            ->delete(route('terminals.destroy', $terminalA))
            ->assertNotFound();

        $this->assertSame('active', $terminalA->fresh()->status);
    }

    public function test_replace_card_preserves_attendance_history(): void
    {
        $ctx = $this->makeOrganization('NfcRep1');
        $admin = $this->makeUser($ctx['organization'], 'company_admin', $ctx['roles']);
        $user = $this->makeUser($ctx['organization'], 'employee', $ctx['roles']);
        $employee = $this->makeEmployee($ctx['organization'], $user, 'EMP-R1');

        $terminal = AttendanceTerminal::create([
            'organization_id' => $ctx['organization']->id,
            'name' => 'TERM-R',
            'location' => 'Lobby',
            'status' => 'active',
            'key_hash' => hash('sha256', 'key-rep'),
        ]);

        $card = NfcCard::create([
            'organization_id' => $ctx['organization']->id,
            'card_token' => 'tok-rep-old',
            'status' => 'active',
            'employee_id' => $employee->id,
        ]);

        $this->withHeaders(['X-Terminal-Key' => 'key-rep'])
            ->postJson('/api/attendance/nfc/punch', ['card_token' => 'tok-rep-old'])
            ->assertOk()
            ->assertJsonPath('action', 'check_in');

        $this->actingAs($admin)
            ->post(route('nfc-cards.replace', $card))
            ->assertRedirect(route('nfc-cards.index'));

        $status = session('status');
        $this->assertIsString($status);
        $this->assertStringContainsString('Card replaced. New token:', $status);

        $old = $card->fresh();
        $this->assertSame('revoked', $old->status);
        $this->assertNotNull($old->revoked_at);
        // The revoked card keeps its employee link so history still resolves.
        $this->assertSame($employee->id, $old->employee_id);

        $newCard = NfcCard::withoutGlobalScopes()
            ->where('organization_id', $ctx['organization']->id)
            ->where('status', 'active')
            ->where('employee_id', $employee->id)
            ->firstOrFail();
        $this->assertNotSame('tok-rep-old', $newCard->card_token);

        $this->assertNotNull(
            AuditLog::withoutGlobalScopes()
                ->where('organization_id', $ctx['organization']->id)
                ->where('action', 'nfc.replaced')
                ->first(),
        );

        // Old token no longer punches.
        $this->withHeaders(['X-Terminal-Key' => 'key-rep'])
            ->postJson('/api/attendance/nfc/punch', ['card_token' => 'tok-rep-old'])
            ->assertUnprocessable();

        // New token punches (state still open from before the replacement → check_out).
        $this->travel(20)->seconds();
        $this->withHeaders(['X-Terminal-Key' => 'key-rep'])
            ->postJson('/api/attendance/nfc/punch', ['card_token' => $newCard->card_token])
            ->assertOk()
            ->assertJsonPath('action', 'check_out');

        // The pre-replacement punch still appears in the card's history.
        $this->actingAs($admin)
            ->get(route('nfc-cards.history', $card))
            ->assertOk()
            ->assertSee('Card History')
            ->assertSee('Punch history')
            ->assertSee('CHECK_IN')
            ->assertSee('tok-rep-old');

        $employeeUser = $this->makeUser($ctx['organization'], 'employee', $ctx['roles']);
        $this->actingAs($employeeUser)
            ->get(route('nfc-cards.history', $card))
            ->assertForbidden();
    }

    // ── §32 — terminal geo-security ────────────────────────────────────

    public function test_ip_allowlist_refuses_punches_from_other_addresses(): void
    {
        $ctx = $this->makeOrganization('TermGeo1');
        $user = $this->makeUser($ctx['organization'], 'employee', $ctx['roles']);
        $employee = $this->makeEmployee($ctx['organization'], $user, 'EMP-G1');

        $terminal = AttendanceTerminal::create([
            'organization_id' => $ctx['organization']->id,
            'name' => 'TERM-GEO-IP',
            'status' => 'active',
            'key_hash' => hash('sha256', 'key-geo-ip'),
            'allowed_ips' => ['203.0.113.7', '10.0.0.0/8'],
        ]);

        NfcCard::create([
            'organization_id' => $ctx['organization']->id,
            'card_token' => 'tok-geo-ip',
            'status' => 'active',
            'employee_id' => $employee->id,
        ]);

        // Default test IP (127.0.0.1) is not on the allowlist → refused.
        $this->withHeaders(['X-Terminal-Key' => 'key-geo-ip'])
            ->postJson('/api/attendance/nfc/punch', ['card_token' => 'tok-geo-ip'])
            ->assertForbidden()
            ->assertJsonPath('message', 'Punch refused: source IP is not on this terminal\'s allowlist.');

        // Exact match on the allowlist → accepted.
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.7'])
            ->withHeaders(['X-Terminal-Key' => 'key-geo-ip'])
            ->postJson('/api/attendance/nfc/punch', ['card_token' => 'tok-geo-ip'])
            ->assertOk()
            ->assertJsonPath('action', 'check_in');

        $this->assertNotNull($terminal->fresh());
    }

    public function test_cidr_allowlist_entry_covers_matching_ips(): void
    {
        $ctx = $this->makeOrganization('TermGeo2');
        $user = $this->makeUser($ctx['organization'], 'employee', $ctx['roles']);
        $employee = $this->makeEmployee($ctx['organization'], $user, 'EMP-G2');

        AttendanceTerminal::create([
            'organization_id' => $ctx['organization']->id,
            'name' => 'TERM-GEO-CIDR',
            'status' => 'active',
            'key_hash' => hash('sha256', 'key-geo-cidr'),
            'allowed_ips' => ['10.0.0.0/8'],
        ]);

        NfcCard::create([
            'organization_id' => $ctx['organization']->id,
            'card_token' => 'tok-geo-cidr',
            'status' => 'active',
            'employee_id' => $employee->id,
        ]);

        $this->withServerVariables(['REMOTE_ADDR' => '10.1.2.3'])
            ->withHeaders(['X-Terminal-Key' => 'key-geo-cidr'])
            ->postJson('/api/attendance/nfc/punch', ['card_token' => 'tok-geo-cidr'])
            ->assertOk()
            ->assertJsonPath('action', 'check_in');
    }

    public function test_location_verification_requires_a_fix_within_the_radius(): void
    {
        $ctx = $this->makeOrganization('TermGeo3');
        $user = $this->makeUser($ctx['organization'], 'employee', $ctx['roles']);
        $employee = $this->makeEmployee($ctx['organization'], $user, 'EMP-G3');

        AttendanceTerminal::create([
            'organization_id' => $ctx['organization']->id,
            'name' => 'TERM-GEO-GPS',
            'status' => 'active',
            'key_hash' => hash('sha256', 'key-geo-gps'),
            'latitude' => 23.8103,
            'longitude' => 90.4125,
            'radius_meters' => 100,
            'require_location' => true,
        ]);

        NfcCard::create([
            'organization_id' => $ctx['organization']->id,
            'card_token' => 'tok-geo-gps',
            'status' => 'active',
            'employee_id' => $employee->id,
        ]);

        // Missing fix → 422.
        $this->withHeaders(['X-Terminal-Key' => 'key-geo-gps'])
            ->postJson('/api/attendance/nfc/punch', ['card_token' => 'tok-geo-gps'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Punch refused: this terminal requires a location fix.');

        // ~1 km away (outside 100 m radius) → 403.
        $this->withHeaders(['X-Terminal-Key' => 'key-geo-gps'])
            ->postJson('/api/attendance/nfc/punch', [
                'card_token' => 'tok-geo-gps',
                'latitude' => 23.8193,
                'longitude' => 90.4125,
            ])
            ->assertForbidden()
            ->assertJsonPath('message', 'Punch refused: location is 1001m away — outside the 100m radius.');

        // Inside the radius → accepted.
        $this->withHeaders(['X-Terminal-Key' => 'key-geo-gps'])
            ->postJson('/api/attendance/nfc/punch', [
                'card_token' => 'tok-geo-gps',
                'latitude' => 23.8104,
                'longitude' => 90.4125,
            ])
            ->assertOk()
            ->assertJsonPath('action', 'check_in');
    }

    public function test_admin_can_register_a_terminal_with_geo_security(): void
    {
        $ctx = $this->makeOrganization('TermGeo4');
        $admin = $this->makeUser($ctx['organization'], 'company_admin', $ctx['roles']);

        $this->actingAs($admin)
            ->post(route('terminals.store'), [
                'name' => 'TERM-GEO-CREATE',
                'location' => 'Head Office',
                'latitude' => 23.8103,
                'longitude' => 90.4125,
                'radius_meters' => 250,
                'require_location' => '1',
                'allowed_ips' => [" 203.0.113.9 \n"],
            ])
            ->assertRedirect(route('terminals.index'));

        $terminal = AttendanceTerminal::withoutGlobalScopes()
            ->where('organization_id', $ctx['organization']->id)
            ->where('name', 'TERM-GEO-CREATE')
            ->firstOrFail();

        $this->assertSame(23.8103, round($terminal->latitude, 4));
        $this->assertSame(90.4125, round($terminal->longitude, 4));
        $this->assertSame(250, $terminal->radius_meters);
        $this->assertTrue($terminal->require_location);
        $this->assertSame(['203.0.113.9'], $terminal->allowed_ips);
    }
}
