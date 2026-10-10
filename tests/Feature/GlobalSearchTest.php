<?php

namespace Tests\Feature;

use App\Models\NfcCard;
use App\Models\Organization;
use App\Models\Position;
use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesOrganizations;
use Tests\TestCase;

/**
 * Global search: employee code / name / email / department / designation
 * plus NFC card tokens, tenant-scoped and RBAC-filtered per section.
 */
class GlobalSearchTest extends TestCase
{
    use CreatesOrganizations;
    use RefreshDatabase;

    private Organization $organization;

    /** @var array<string, Role> */
    private array $roles;

    protected function setUp(): void
    {
        parent::setUp();

        $ctx = $this->makeOrganization('Search '.uniqid());
        $this->organization = $ctx['organization'];
        $this->roles = $ctx['roles'];
    }

    public function test_requires_authentication(): void
    {
        $this->get('/search?q=EMP')->assertRedirect('/login');
    }

    public function test_short_or_empty_query_returns_no_results(): void
    {
        $admin = $this->makeUser($this->organization, 'company_admin', $this->roles);

        foreach (['', 'E'] as $term) {
            $this->actingAs($admin)
                ->getJson('/search?q='.urlencode($term))
                ->assertOk()
                ->assertExactJson(['employees' => [], 'cards' => []]);
        }
    }

    public function test_finds_employees_by_code_name_email_department_and_designation(): void
    {
        $admin = $this->makeUser($this->organization, 'company_admin', $this->roles);

        $department = $this->makeDepartment($this->organization, 'Quality Control');
        $position = Position::create([
            'organization_id' => $this->organization->id,
            'department_id' => $department->id,
            'title' => 'QA Engineer',
        ]);

        $target = $this->makeUser($this->organization, 'employee', $this->roles, [
            'name' => 'Ada Searchable',
            'email' => 'ada.search@example.test',
        ]);
        $employee = $this->makeEmployee($this->organization, $target, 'EMP-SRCH');
        $employee->update(['department_id' => $department->id, 'position_id' => $position->id]);

        // Noise in the same org must never appear.
        $noise = $this->makeUser($this->organization, 'employee', $this->roles, ['name' => 'Nobody Match']);
        $this->makeEmployee($this->organization, $noise, 'EMP-NOISE');

        $terms = [
            'EMP-SRCH' => 'employee code',
            'Ada Searchable' => 'name',
            'ada.search@example.test' => 'email',
            'Quality Control' => 'department',
            'QA Engineer' => 'designation',
        ];

        foreach ($terms as $term => $field) {
            $json = $this->actingAs($admin)
                ->getJson('/search?q='.urlencode($term))
                ->assertOk()
                ->json();

            $this->assertCount(1, $json['employees'], "Expected exactly one hit for {$field}.");
            $this->assertSame('EMP-SRCH', $json['employees'][0]['code']);
            $this->assertSame('Ada Searchable', $json['employees'][0]['name']);
            $this->assertSame('Quality Control', $json['employees'][0]['department']);
            $this->assertSame('QA Engineer', $json['employees'][0]['designation']);
            $this->assertSame(route('employees.show', $employee), $json['employees'][0]['url']);
        }
    }

    public function test_plain_employee_without_permissions_gets_empty_results(): void
    {
        $employee = $this->makeUser($this->organization, 'employee', $this->roles);
        $this->makeEmployee($this->organization, $employee, 'EMP-PLAIN');

        $this->actingAs($employee)
            ->getJson('/search?q=EMP')
            ->assertOk()
            ->assertExactJson(['employees' => [], 'cards' => []]);
    }

    public function test_manager_only_finds_their_own_team(): void
    {
        $manager = $this->makeUser($this->organization, 'manager', $this->roles);

        $managed = $this->makeUser($this->organization, 'employee', $this->roles, ['name' => 'Team Member']);
        $managedEmployee = $this->makeEmployee($this->organization, $managed, 'EMP-TEAM');
        $managedEmployee->update(['manager_id' => $manager->id]);

        $other = $this->makeUser($this->organization, 'employee', $this->roles, ['name' => 'Other Member']);
        $this->makeEmployee($this->organization, $other, 'EMP-OTHER');

        $json = $this->actingAs($manager)
            ->getJson('/search?q=Member')
            ->assertOk()
            ->json();

        $this->assertCount(1, $json['employees']);
        $this->assertSame('EMP-TEAM', $json['employees'][0]['code']);
    }

    public function test_cards_only_appear_for_users_with_nfc_view(): void
    {
        NfcCard::create([
            'organization_id' => $this->organization->id,
            'card_token' => 'NFC-SEARCHME01',
            'status' => 'active',
            'issued_at' => now(),
        ]);

        $hr = $this->makeUser($this->organization, 'hr', $this->roles);
        $manager = $this->makeUser($this->organization, 'manager', $this->roles);

        $asHr = $this->actingAs($hr)->getJson('/search?q=NFC-SEARCHME')->assertOk()->json();
        $this->assertCount(1, $asHr['cards']);
        $this->assertSame('NFC-SEARCHME01', $asHr['cards'][0]['token']);
        $this->assertSame(route('nfc-cards.index', ['q' => 'NFC-SEARCHME01']), $asHr['cards'][0]['url']);

        $asManager = $this->actingAs($manager)->getJson('/search?q=NFC-SEARCHME')->assertOk()->json();
        $this->assertEmpty($asManager['cards']);
        $this->assertEmpty($asManager['employees']);
    }

    public function test_results_are_tenant_isolated(): void
    {
        $admin = $this->makeUser($this->organization, 'company_admin', $this->roles);

        $otherCtx = $this->makeOrganization('Other Search');
        $foreign = $this->makeUser($otherCtx['organization'], 'employee', $otherCtx['roles'], ['name' => 'Foreign Person']);
        $this->makeEmployee($otherCtx['organization'], $foreign, 'EMP-FOREIGN');

        $json = $this->actingAs($admin)
            ->getJson('/search?q=Foreign')
            ->assertOk()
            ->json();

        $this->assertEmpty($json['employees']);
        $this->assertEmpty($json['cards']);
    }

    public function test_navbar_search_box_renders_only_for_permitted_users(): void
    {
        $admin = $this->makeUser($this->organization, 'company_admin', $this->roles);

        $this->actingAs($admin)
            ->get(route('dashboard.admin'))
            ->assertOk()
            ->assertSee('Search employees and cards');

        $employee = $this->makeUser($this->organization, 'employee', $this->roles);

        $this->actingAs($employee)
            ->get(route('dashboard.employee'))
            ->assertOk()
            ->assertDontSee('Search employees and cards');
    }
}
