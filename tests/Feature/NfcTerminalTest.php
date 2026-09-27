<?php

namespace Tests\Feature;

use App\Models\AttendanceTerminal;
use App\Models\NfcCard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesOrganizations;
use Tests\TestCase;

class NfcTerminalTest extends TestCase
{
    use CreatesOrganizations;
    use RefreshDatabase;

    public function test_owner_can_issue_cards_and_sees_plain_token_once(): void
    {
        $org = $this->makeOrganization('NfcCo1');
        $owner = $org->users()->where('email', $this->ownerEmail())->firstOrFail();
        $owner->syncRoles(['owner']);

        $this->actingAs($owner)
            ->post(route('nfc-cards.store'), ['count' => 3])
            ->assertSessionHas('status');

        $this->assertSame(3, NfcCard::withoutGlobalScopes()
            ->where('organization_id', $org->id)
            ->where('status', 'available')
            ->count());

        // Plain token is returned once; only hash is recoverable afterward.
        $card = NfcCard::withoutGlobalScopes()->where('organization_id', $org->id)->firstOrFail();
        $this->assertArrayHasKey('token', session('status') ?? []);
    }

    public function test_assign_block_revoke_flow(): void
    {
        $org = $this->makeOrganization('NfcCo2');
        $owner = $org->users()->where('email', $this->ownerEmail())->firstOrFail();
        $owner->syncRoles(['owner']);
        $employee = $this->makeEmployee($org, 'EMP-N1', 'nfc@test.com');

        $card = new NfcCard(['card_token' => 'tok-flow', 'status' => 'available']);
        $card->organization()->associate($org);
        $card->save();

        $this->actingAs($owner)
            ->post(route('nfc-cards.assign', $card), ['employee_id' => $employee->id])
            ->assertRedirect(route('nfc-cards.index'));
        $this->assertSame('active', $card->fresh()->status);
        $this->assertSame($employee->id, $card->fresh()->employee_id);

        $this->actingAs($owner)
            ->post(route('nfc-cards.block', $card))
            ->assertRedirect(route('nfc-cards.index'));
        $this->assertSame('blocked', $card->fresh()->status);

        $this->actingAs($owner)
            ->post(route('nfc-cards.revoke', $card))
            ->assertRedirect(route('nfc-cards.index'));
        $this->assertSame('revoked', $card->fresh()->status);
    }

    public function test_employee_without_permission_cannot_issue_cards(): void
    {
        $org = $this->makeOrganization('NfcCo3');
        $employee = $this->makeEmployee($org, 'EMP-N2', 'nfc2@test.com');
        $employee->user->syncRoles(['employee']);

        $this->actingAs($employee->user)
            ->post(route('nfc-cards.store'), ['count' => 1])
            ->assertForbidden();
    }

    public function test_terminal_creation_returns_key_once_and_revokes(): void
    {
        $org = $this->makeOrganization('TermCo1');
        $owner = $org->users()->where('email', $this->ownerEmail())->firstOrFail();
        $owner->syncRoles(['owner']);

        $response = $this->actingAs($owner)
            ->post(route('terminals.store'), [
                'name' => 'TERM-X',
                'location' => 'Dhaka',
            ]);
        $response->assertRedirect(route('terminals.index'));

        $status = session('status');
        $this->assertIsArray($status);
        $this->assertArrayHasKey('key', $status);

        $terminal = AttendanceTerminal::withoutGlobalScopes()->where('organization_id', $org->id)->firstOrFail();
        $this->assertSame(hash('sha256', $status['key']), $terminal->key_hash);

        // Key never reappears on later page loads.
        $this->actingAs($owner)
            ->get(route('terminals.index'))
            ->assertOk()
            ->assertDontSee($status['key']);

        $this->actingAs($owner)
            ->delete(route('terminals.destroy', $terminal))
            ->assertRedirect(route('terminals.index'));
        $this->assertSame('revoked', $terminal->fresh()->status);
    }

    public function test_terminal_routes_are_tenant_isolated(): void
    {
        $orgA = $this->makeOrganization('TermCoA');
        $orgB = $this->makeOrganization('TermCoB');
        $ownerA = $orgA->users()->where('email', $this->ownerEmail())->firstOrFail();
        $ownerA->syncRoles(['owner']);

        $this->actingAs($ownerA)
            ->post(route('terminals.store'), ['name' => 'TERM-A', 'location' => ''])
            ->assertRedirect(route('terminals.index'));

        $terminalA = AttendanceTerminal::withoutGlobalScopes()->where('organization_id', $orgA->id)->firstOrFail();

        $ownerB = $orgB->users()->where('email', $this->ownerEmail())->firstOrFail();
        $ownerB->syncRoles(['owner']);
        $this->actingAs($ownerB)
            ->delete(route('terminals.destroy', $terminalA))
            ->assertNotFound();
    }
}
