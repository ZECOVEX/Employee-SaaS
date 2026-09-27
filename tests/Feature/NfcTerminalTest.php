<?php

namespace Tests\Feature;

use App\Models\AttendanceTerminal;
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
}
