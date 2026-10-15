<?php

namespace Tests\Feature;

use App\Models\AttendanceTerminal;
use App\Models\AuditLog;
use App\Models\NfcCard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesOrganizations;
use Tests\TestCase;

class NfcIssueTest extends TestCase
{
    use CreatesOrganizations;
    use RefreshDatabase;

    public function test_issue_panel_is_visible_to_nfc_manage_admins_on_the_cards_page(): void
    {
        $ctx = $this->makeOrganization('NfcIssue1');
        $admin = $this->makeUser($ctx['organization'], 'company_admin', $ctx['roles']);

        $this->actingAs($admin)
            ->get(route('nfc-cards.index'))
            ->assertOk()
            ->assertSee('Issue a card')
            ->assertSee('Phone NFC')
            ->assertSee('Reader machine')
            ->assertSee('Manual code')
            ->assertSee('Issue to employee')
            ->assertSee(route('nfc-cards.issue.store'));
    }

    public function test_roles_without_nfc_manage_cannot_see_or_use_the_issue_panel(): void
    {
        $ctx = $this->makeOrganization('NfcIssue2');
        $employeeUser = $this->makeUser($ctx['organization'], 'employee', $ctx['roles']);
        $hrUser = $this->makeUser($ctx['organization'], 'hr', $ctx['roles']);

        // HR has nfc.view — the cards list loads, but the issue panel is
        // rendered behind @can('nfc.manage') and never appears.
        $this->actingAs($hrUser)
            ->get(route('nfc-cards.index'))
            ->assertOk()
            ->assertDontSee('Issue to employee')
            ->assertDontSee('NFC issue');

        // Employees lack nfc.view entirely.
        $this->actingAs($employeeUser)
            ->get(route('nfc-cards.index'))
            ->assertForbidden();

        foreach ([$employeeUser, $hrUser] as $user) {
            $this->actingAs($user)
                ->post(route('nfc-cards.issue.store'), ['card_token' => 'NFC-NOPE01'])
                ->assertForbidden();
        }
    }

    public function test_admin_issues_a_card_with_a_typed_code(): void
    {
        $ctx = $this->makeOrganization('NfcIssue3');
        $admin = $this->makeUser($ctx['organization'], 'company_admin', $ctx['roles']);
        $user = $this->makeUser($ctx['organization'], 'employee', $ctx['roles']);
        $employee = $this->makeEmployee($ctx['organization'], $user, 'EMP-N1');

        $this->actingAs($admin)
            ->post(route('nfc-cards.issue.store'), [
                'employee_id' => $employee->id,
                'card_token' => 'NFC-ISSUE01',
                'method' => 'phone',
            ])
            ->assertRedirect(route('nfc-cards.index'));

        $this->assertStringContainsString(
            'Card NFC-ISSUE01 issued to EMP-N1.',
            (string) session('status'),
        );

        $card = NfcCard::withoutGlobalScopes()
            ->where('organization_id', $ctx['organization']->id)
            ->firstOrFail();
        $this->assertSame('NFC-ISSUE01', $card->card_token);
        $this->assertSame('active', $card->status);
        $this->assertSame($employee->id, $card->employee_id);
        $this->assertNotNull($card->issued_at);

        $audit = AuditLog::withoutGlobalScopes()
            ->where('organization_id', $ctx['organization']->id)
            ->where('action', 'nfc.issued')
            ->first();
        $this->assertNotNull($audit);
        $this->assertSame('phone', $audit->new_value['method'] ?? null);
    }

    public function test_captured_code_is_normalized_before_storing(): void
    {
        $ctx = $this->makeOrganization('NfcIssue4');
        $admin = $this->makeUser($ctx['organization'], 'company_admin', $ctx['roles']);
        $user = $this->makeUser($ctx['organization'], 'employee', $ctx['roles']);
        $employee = $this->makeEmployee($ctx['organization'], $user, 'EMP-N2');

        $this->actingAs($admin)
            ->post(route('nfc-cards.issue.store'), [
                'employee_id' => $employee->id,
                'card_token' => ' 04:6e 3a:2b ',
                'method' => 'phone',
            ])
            ->assertRedirect(route('nfc-cards.index'));

        $card = NfcCard::withoutGlobalScopes()
            ->where('organization_id', $ctx['organization']->id)
            ->firstOrFail();
        $this->assertSame('046E3A2B', $card->card_token);
    }

    public function test_existing_available_card_is_claimed_not_duplicated(): void
    {
        $ctx = $this->makeOrganization('NfcIssue5');
        $admin = $this->makeUser($ctx['organization'], 'company_admin', $ctx['roles']);
        $user = $this->makeUser($ctx['organization'], 'employee', $ctx['roles']);
        $employee = $this->makeEmployee($ctx['organization'], $user, 'EMP-N3');

        $available = NfcCard::create([
            'organization_id' => $ctx['organization']->id,
            'card_token' => 'NFC-CLAIM01',
            'status' => 'available',
        ]);

        $this->actingAs($admin)
            ->post(route('nfc-cards.issue.store'), [
                'employee_id' => $employee->id,
                'card_token' => 'nfc-claim01',
                'method' => 'reader',
            ])
            ->assertRedirect(route('nfc-cards.index'));

        $this->assertSame(1, NfcCard::withoutGlobalScopes()
            ->where('organization_id', $ctx['organization']->id)
            ->count());

        $fresh = $available->fresh();
        $this->assertSame('active', $fresh->status);
        $this->assertSame($employee->id, $fresh->employee_id);
        $this->assertNotNull($fresh->issued_at);

        $this->assertNotNull(
            AuditLog::withoutGlobalScopes()
                ->where('organization_id', $ctx['organization']->id)
                ->where('action', 'nfc.assigned')
                ->first(),
        );
    }

    public function test_already_active_code_is_rejected(): void
    {
        $ctx = $this->makeOrganization('NfcIssue6');
        $admin = $this->makeUser($ctx['organization'], 'company_admin', $ctx['roles']);
        $userA = $this->makeUser($ctx['organization'], 'employee', $ctx['roles']);
        $employeeA = $this->makeEmployee($ctx['organization'], $userA, 'EMP-N4');
        $userB = $this->makeUser($ctx['organization'], 'employee', $ctx['roles']);
        $employeeB = $this->makeEmployee($ctx['organization'], $userB, 'EMP-N5');

        $card = NfcCard::create([
            'organization_id' => $ctx['organization']->id,
            'card_token' => 'NFC-USED01',
            'status' => 'active',
            'employee_id' => $employeeA->id,
            'issued_at' => now(),
        ]);

        $this->actingAs($admin)
            ->post(route('nfc-cards.issue.store'), [
                'employee_id' => $employeeB->id,
                'card_token' => 'nfc-used01',
                'method' => 'manual',
            ])
            ->assertSessionHasErrors('card_token');

        $fresh = $card->fresh();
        $this->assertSame('active', $fresh->status);
        $this->assertSame($employeeA->id, $fresh->employee_id);
        $this->assertSame(1, NfcCard::withoutGlobalScopes()
            ->where('organization_id', $ctx['organization']->id)
            ->count());
    }

    public function test_employee_from_another_organization_is_rejected(): void
    {
        $ctxA = $this->makeOrganization('NfcIssue7');
        $ctxB = $this->makeOrganization('NfcIssue7b');
        $adminA = $this->makeUser($ctxA['organization'], 'company_admin', $ctxA['roles']);
        $userB = $this->makeUser($ctxB['organization'], 'employee', $ctxB['roles']);
        $employeeB = $this->makeEmployee($ctxB['organization'], $userB, 'EMP-X1');

        $this->actingAs($adminA)
            ->post(route('nfc-cards.issue.store'), [
                'employee_id' => $employeeB->id,
                'card_token' => 'NFC-CROSS01',
                'method' => 'manual',
            ])
            ->assertSessionHasErrors('employee_id');

        $this->assertSame(0, NfcCard::withoutGlobalScopes()
            ->where('organization_id', $ctxA['organization']->id)
            ->count());
    }

    public function test_one_merged_page_holds_issue_and_cards_and_employees_never_see_it(): void
    {
        $ctx = $this->makeOrganization('NfcIssue8');
        $admin = $this->makeUser($ctx['organization'], 'company_admin', $ctx['roles']);
        $employeeUser = $this->makeUser($ctx['organization'], 'employee', $ctx['roles']);

        NfcCard::create([
            'organization_id' => $ctx['organization']->id,
            'card_token' => 'NFC-MERGE01',
            'status' => 'available',
        ]);

        // One page: the issue panel, the cards list actions and the
        // Terminals section link all live together — no separate entry.
        $this->actingAs($admin)
            ->get(route('nfc-cards.index'))
            ->assertOk()
            ->assertSee('Issue a card')
            ->assertSee('Terminals')
            ->assertSee('Assign to…')
            ->assertDontSee('NFC issue');

        // Employees never see the panel or any issue affordance.
        $this->actingAs($employeeUser)
            ->get(route('dashboard.employee'))
            ->assertOk()
            ->assertDontSee('Issue a card')
            ->assertDontSee('NFC issue');
    }

    public function test_punch_accepts_a_colon_separated_capture_of_an_issued_code(): void
    {
        $ctx = $this->makeOrganization('NfcIssue9');
        $admin = $this->makeUser($ctx['organization'], 'company_admin', $ctx['roles']);
        $user = $this->makeUser($ctx['organization'], 'employee', $ctx['roles']);
        $employee = $this->makeEmployee($ctx['organization'], $user, 'EMP-N6');

        $this->actingAs($admin)
            ->post(route('nfc-cards.issue.store'), [
                'employee_id' => $employee->id,
                'card_token' => '04:6e:3a:2b',
                'method' => 'phone',
            ])
            ->assertRedirect(route('nfc-cards.index'));

        AttendanceTerminal::create([
            'organization_id' => $ctx['organization']->id,
            'name' => 'TERM-ISSUE',
            'location' => 'Lobby',
            'status' => 'active',
            'key_hash' => hash('sha256', 'key-issue'),
        ]);

        // The terminal emits the same UID with colons — still matches.
        $this->withHeaders(['X-Terminal-Key' => 'key-issue'])
            ->postJson('/api/attendance/nfc/punch', ['card_token' => '04:6E:3A:2B'])
            ->assertOk()
            ->assertJsonPath('action', 'check_in');
    }
}
