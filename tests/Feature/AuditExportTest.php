<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesOrganizations;
use Tests\TestCase;

class AuditExportTest extends TestCase
{
    use CreatesOrganizations, RefreshDatabase;

    public function test_export_requires_the_audit_view_permission(): void
    {
        ['organization' => $org, 'roles' => $roles] = $this->makeOrganization();
        $employee = $this->makeUser($org, 'employee', $roles);
        $admin = $this->makeUser($org, 'company_admin', $roles);

        $this->actingAs($employee)->get(route('audit-logs.export'))->assertForbidden();
        $this->actingAs($admin)->get(route('audit-logs.export'))->assertOk();
    }

    public function test_export_downloads_a_csv_with_the_filtered_rows(): void
    {
        ['organization' => $org, 'roles' => $roles] = $this->makeOrganization();
        $admin = $this->makeUser($org, 'company_admin', $roles);

        AuditLog::create([
            'organization_id' => $org->id,
            'actor_user_id' => $admin->id,
            'action' => 'employee.created',
            'resource_type' => 'Employee',
            'resource_id' => 1,
            'new_value' => ['employee_code' => 'EMP-1'],
        ]);

        AuditLog::create([
            'organization_id' => $org->id,
            'actor_user_id' => $admin->id,
            'action' => 'settings.updated',
        ]);

        $response = $this->actingAs($admin)->get(route('audit-logs.export', ['action' => 'employee.created']));

        $response->assertOk();
        $this->assertStringContainsString('text/csv', $response->headers->get('Content-Type'));

        $csv = $response->streamedContent();
        $this->assertStringContainsString('id,created_at,actor,action', $csv);
        $this->assertStringContainsString('employee.created', $csv);
        $this->assertStringNotContainsString('settings.updated', $csv);
        $this->assertStringContainsString($admin->email, $csv);
    }

    public function test_export_is_tenant_scoped(): void
    {
        ['organization' => $org, 'roles' => $roles] = $this->makeOrganization();
        ['organization' => $other, 'roles' => $otherRoles] = $this->makeOrganization('Other Co');

        $admin = $this->makeUser($org, 'company_admin', $roles);
        $otherAdmin = $this->makeUser($other, 'company_admin', $otherRoles);

        AuditLog::create([
            'organization_id' => $org->id,
            'actor_user_id' => $admin->id,
            'action' => 'org.only.action',
        ]);

        AuditLog::create([
            'organization_id' => $other->id,
            'actor_user_id' => $otherAdmin->id,
            'action' => 'other.only.action',
        ]);

        $csv = $this->actingAs($admin)->get(route('audit-logs.export'))->streamedContent();

        $this->assertStringContainsString('org.only.action', $csv);
        $this->assertStringNotContainsString('other.only.action', $csv);
    }
}
