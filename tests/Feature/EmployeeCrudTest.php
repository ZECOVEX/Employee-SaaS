<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesOrganizations;
use Tests\TestCase;

class EmployeeCrudTest extends TestCase
{
    use CreatesOrganizations, RefreshDatabase;

    public function test_admin_can_create_employee_and_audit_is_written(): void
    {
        ['organization' => $org, 'roles' => $roles] = $this->makeOrganization();
        $admin = $this->makeUser($org, 'company_admin', $roles);
        $department = $this->makeDepartment($org);

        $response = $this->actingAs($admin)->post(route('employees.store'), [
            'name' => 'Jane Doe',
            'email' => 'jane@acme.test',
            'password' => 'password',
            'password_confirmation' => 'password',
            'employee_code' => 'EMP-042',
            'department_id' => $department->id,
            'status' => 'active',
            'employment_type' => 'full_time',
        ]);

        $employee = Employee::where('employee_code', 'EMP-042')->firstOrFail();
        $response->assertRedirect(route('employees.show', $employee));

        $this->assertDatabaseHas('users', [
            'organization_id' => $org->id,
            'email' => 'jane@acme.test',
        ]);

        $this->assertTrue(
            AuditLog::where('organization_id', $org->id)
                ->where('action', 'employee.created')
                ->where('actor_user_id', $admin->id)
                ->exists()
        );
    }

    public function test_admin_can_update_employee(): void
    {
        ['organization' => $org, 'roles' => $roles] = $this->makeOrganization();
        $admin = $this->makeUser($org, 'company_admin', $roles);
        $user = $this->makeUser($org, 'employee', $roles, ['email' => 'e@acme.test']);
        $employee = $this->makeEmployee($org, $user, 'EMP-007');

        $this->actingAs($admin)
            ->put(route('employees.update', $employee), [
                'name' => 'Renamed Person',
                'email' => 'e@acme.test',
                'status' => 'inactive',
                'employment_type' => 'full_time',
            ])
            ->assertRedirect(route('employees.show', $employee));

        $this->assertSame('inactive', $employee->fresh()->status);
        $this->assertSame('Renamed Person', $user->fresh()->name);

        $this->assertTrue(
            AuditLog::where('action', 'employee.updated')
                ->where('organization_id', $org->id)
                ->exists()
        );
    }

    public function test_duplicate_employee_code_rejected_within_tenant(): void
    {
        ['organization' => $org, 'roles' => $roles] = $this->makeOrganization();
        $admin = $this->makeUser($org, 'company_admin', $roles);
        $user = $this->makeUser($org, 'employee', $roles);
        $this->makeEmployee($org, $user, 'EMP-DUP');

        $this->actingAs($admin)
            ->post(route('employees.store'), [
                'name' => 'Dup',
                'email' => 'dup@acme.test',
                'password' => 'password',
                'password_confirmation' => 'password',
                'employee_code' => 'EMP-DUP',
                'status' => 'active',
                'employment_type' => 'full_time',
            ])
            ->assertSessionHasErrors('employee_code');
    }

    public function test_hr_can_create_department(): void
    {
        ['organization' => $org, 'roles' => $roles] = $this->makeOrganization();
        $hr = $this->makeUser($org, 'hr', $roles);

        $this->actingAs($hr)
            ->post(route('departments.store'), [
                'name' => 'Finance',
                'code' => 'FIN',
            ])
            ->assertRedirect(route('departments.index'));

        $this->assertDatabaseHas('departments', [
            'organization_id' => $org->id,
            'name' => 'Finance',
        ]);
    }

    public function test_admin_can_upload_employee_photo(): void
    {
        Storage::fake('public');

        ['organization' => $org, 'roles' => $roles] = $this->makeOrganization();
        $admin = $this->makeUser($org, 'company_admin', $roles);
        $user = $this->makeUser($org, 'employee', $roles, ['email' => 'photo@acme.test']);
        $employee = $this->makeEmployee($org, $user, 'EMP-PH1');

        // Real 1x1 PNG bytes so the `image` rule passes without the GD extension.
        $png = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='
        );
        $photo = UploadedFile::fake()->createWithContent('photo.png', $png);

        $this->actingAs($admin)
            ->put(route('employees.update', $employee), [
                'name' => 'Photo Person',
                'email' => 'photo@acme.test',
                'status' => 'active',
                'employment_type' => 'full_time',
                'photo' => $photo,
            ])
            ->assertRedirect(route('employees.show', $employee));

        $employee->refresh();
        $this->assertNotNull($employee->photo_path);
        Storage::disk('public')->assertExists($employee->photo_path);

        // Replacing removes the previous file.
        $firstPath = $employee->photo_path;
        $second = UploadedFile::fake()->createWithContent('photo2.png', $png);

        $this->actingAs($admin)
            ->put(route('employees.update', $employee), [
                'name' => 'Photo Person',
                'email' => 'photo@acme.test',
                'status' => 'active',
                'employment_type' => 'full_time',
                'photo' => $second,
            ])
            ->assertRedirect(route('employees.show', $employee));

        $employee->refresh();
        $this->assertNotSame($firstPath, $employee->photo_path);
        Storage::disk('public')->assertMissing($firstPath);
        Storage::disk('public')->assertExists($employee->photo_path);
    }

    public function test_rejects_non_image_photo_upload(): void
    {
        Storage::fake('public');

        ['organization' => $org, 'roles' => $roles] = $this->makeOrganization();
        $admin = $this->makeUser($org, 'company_admin', $roles);
        $user = $this->makeUser($org, 'employee', $roles, ['email' => 'noimg@acme.test']);
        $employee = $this->makeEmployee($org, $user, 'EMP-PH2');

        $fake = UploadedFile::fake()->create('resume.pdf', 10, 'application/pdf');

        $this->actingAs($admin)
            ->put(route('employees.update', $employee), [
                'name' => 'No Image',
                'email' => 'noimg@acme.test',
                'status' => 'active',
                'employment_type' => 'full_time',
                'photo' => $fake,
            ])
            ->assertSessionHasErrors('photo');

        $this->assertNull($employee->fresh()->photo_path);
    }

    public function test_admin_uploaded_photo_mirrors_to_user_avatar_and_shows_in_nav(): void
    {
        Storage::fake('public');

        ['organization' => $org, 'roles' => $roles] = $this->makeOrganization();
        $admin = $this->makeUser($org, 'company_admin', $roles);
        $user = $this->makeUser($org, 'employee', $roles, ['email' => 'mirror@acme.test']);
        $employee = $this->makeEmployee($org, $user, 'EMP-MR1');

        // A self-uploaded avatars/ file is superseded by the admin photo.
        Storage::disk('public')->put('avatars/self.png', 'x');
        $user->update(['avatar_path' => 'avatars/self.png']);

        $png = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='
        );
        $photo = UploadedFile::fake()->createWithContent('photo.png', $png);

        $this->actingAs($admin)
            ->put(route('employees.update', $employee), [
                'name' => 'Mirror Person',
                'email' => 'mirror@acme.test',
                'status' => 'active',
                'employment_type' => 'full_time',
                'photo' => $photo,
            ])
            ->assertRedirect(route('employees.show', $employee));

        $employee->refresh();
        $user->refresh();

        // One image everywhere: the user row points at the admin-set photo.
        $this->assertSame($employee->photo_path, $user->avatar_path);
        Storage::disk('public')->assertExists($user->avatar_path);
        Storage::disk('public')->assertMissing('avatars/self.png');

        // The employee's dashboard (nav avatar + profile card) shows it.
        $this->actingAs($user)
            ->get(route('dashboard.employee'))
            ->assertOk()
            ->assertSee('storage/employees/');

        // Replacing the photo keeps the mirror in sync and drops the old file.
        $firstPath = $employee->photo_path;
        $second = UploadedFile::fake()->createWithContent('photo2.png', $png);

        $this->actingAs($admin)
            ->put(route('employees.update', $employee), [
                'name' => 'Mirror Person',
                'email' => 'mirror@acme.test',
                'status' => 'active',
                'employment_type' => 'full_time',
                'photo' => $second,
            ])
            ->assertRedirect(route('employees.show', $employee));

        $this->assertNotSame($firstPath, $employee->refresh()->photo_path);
        $this->assertSame($employee->photo_path, $user->refresh()->avatar_path);
        Storage::disk('public')->assertMissing($firstPath);
        Storage::disk('public')->assertExists($user->avatar_path);
    }

    public function test_avatar_falls_back_to_employee_photo_when_user_avatar_missing(): void
    {
        ['organization' => $org, 'roles' => $roles] = $this->makeOrganization();
        $user = $this->makeUser($org, 'employee', $roles, ['email' => 'legacy@acme.test']);
        $employee = $this->makeEmployee($org, $user, 'EMP-LG1');

        // Historical row: admin photo set before the Post-Phase 20b mirror
        // existed — the nav avatar must still render it, not initials.
        $employee->update(['photo_path' => 'employees/'.$employee->id.'/legacy.png']);

        $this->actingAs($user)
            ->get(route('dashboard.employee'))
            ->assertOk()
            ->assertSee('storage/employees/'.$employee->id.'/legacy.png');
    }

    public function test_employee_index_filters_by_department(): void
    {
        ['organization' => $org, 'roles' => $roles] = $this->makeOrganization();
        $admin = $this->makeUser($org, 'company_admin', $roles);
        $engineering = $this->makeDepartment($org);
        $sales = $this->makeDepartment($org, 'Sales');

        $userA = $this->makeUser($org, 'employee', $roles, ['email' => 'filter-a@acme.test']);
        $employeeA = $this->makeEmployee($org, $userA, 'EMP-FA');
        $userB = $this->makeUser($org, 'employee', $roles, ['email' => 'filter-b@acme.test']);
        $employeeB = $this->makeEmployee($org, $userB, 'EMP-FB');

        $employeeA->update(['department_id' => $engineering->id]);
        $employeeB->update(['department_id' => $sales->id]);

        $this->actingAs($admin)
            ->get(route('employees.index', ['department_id' => $engineering->id]))
            ->assertOk()
            ->assertSee('EMP-FA')
            ->assertDontSee('EMP-FB');
    }

    public function test_employee_create_syncs_extended_profile_fields(): void
    {
        ['organization' => $org, 'roles' => $roles] = $this->makeOrganization();
        $admin = $this->makeUser($org, 'company_admin', $roles);

        $response = $this->actingAs($admin)->post(route('employees.store'), [
            'name' => 'Full Fields',
            'email' => 'full@acme.test',
            'password' => 'password',
            'password_confirmation' => 'password',
            'employee_code' => 'EMP-FULL',
            'status' => 'active',
            'employment_type' => 'full_time',
            'phone' => '+8801800000000',
            'address' => '12 Lake Road',
            'date_of_birth' => '1995-04-12',
            'work_location' => 'Dhaka HQ',
            'emergency_contact_name' => 'Rita Doe',
            'emergency_contact_phone' => '+8801711111111',
            'overtime_eligibility' => Employee::OT_NOT_ELIGIBLE,
        ]);

        $employee = Employee::where('employee_code', 'EMP-FULL')->firstOrFail();
        $response->assertRedirect(route('employees.show', $employee));

        $this->assertDatabaseHas('employees', [
            'organization_id' => $org->id,
            'employee_code' => 'EMP-FULL',
            'address' => '12 Lake Road',
            'work_location' => 'Dhaka HQ',
            'emergency_contact_name' => 'Rita Doe',
            'emergency_contact_phone' => '+8801711111111',
            'overtime_eligibility' => Employee::OT_NOT_ELIGIBLE,
        ]);
        $this->assertSame('1995-04-12', $employee->fresh()->date_of_birth?->toDateString());
    }

    public function test_employee_show_renders_header_cards_overview_fields_and_tabs(): void
    {
        ['organization' => $org, 'roles' => $roles] = $this->makeOrganization();
        $admin = $this->makeUser($org, 'company_admin', $roles);
        $user = $this->makeUser($org, 'employee', $roles, ['email' => 'show@acme.test']);
        $employee = $this->makeEmployee($org, $user, 'EMP-SHOW');
        $employee->update([
            'address' => '9 Palm Ave',
            'work_location' => 'Remote',
            'emergency_contact_name' => 'Sam Doe',
            'overtime_eligibility' => Employee::OT_ELIGIBLE,
        ]);

        $this->actingAs($admin)
            ->get(route('employees.show', $employee))
            ->assertOk()
            // §40 header cards.
            ->assertSee('Attendance Score')
            ->assertSee('Overtime This Month')
            // Extended overview fields.
            ->assertSee('Work location')
            ->assertSee('9 Palm Ave')
            ->assertSee('Remote')
            ->assertSee('Emergency contact')
            ->assertSee('Sam Doe')
            ->assertSee('Overtime eligibility')
            ->assertSee('Always eligible')
            // Tabs.
            ->assertSee('Monthly Status')
            ->assertSee('Leave')
            ->assertSee('NFC Card')
            ->assertSee('Audit History')
            // Salary card action (salary.edit holders get the change label).
            ->assertSee('Change salary');
    }

    public function test_manager_dropdown_never_leaks_other_tenants(): void
    {
        ['organization' => $org, 'roles' => $roles] = $this->makeOrganization();
        $admin = $this->makeUser($org, 'company_admin', $roles);
        $this->makeUser($org, 'manager', $roles, ['name' => 'Local Leader']);

        ['organization' => $otherOrg, 'roles' => $otherRoles] = $this->makeOrganization('Other Co');
        $this->makeUser($otherOrg, 'manager', $otherRoles, ['name' => 'Foreign Manager']);

        $this->actingAs($admin)
            ->get(route('employees.create'))
            ->assertOk()
            ->assertSee('Local Leader')
            ->assertDontSee('Foreign Manager');
    }

    public function test_create_form_offers_the_photo_upload_and_store_saves_it(): void
    {
        Storage::fake('public');

        ['organization' => $org, 'roles' => $roles] = $this->makeOrganization();
        $admin = $this->makeUser($org, 'company_admin', $roles);

        // The create form must offer the field AND post it as multipart,
        // otherwise the browser silently drops the file.
        $this->actingAs($admin)
            ->get(route('employees.create'))
            ->assertOk()
            ->assertSee('enctype="multipart/form-data"', false)
            ->assertSee('name="photo"', false);

        $png = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='
        );

        $this->actingAs($admin)
            ->post(route('employees.store'), [
                'name' => 'New Hire',
                'email' => 'newhire@acme.test',
                'password' => 'password',
                'password_confirmation' => 'password',
                'employee_code' => 'EMP-NEW1',
                'status' => 'active',
                'employment_type' => 'full_time',
                'photo' => UploadedFile::fake()->createWithContent('id.png', $png),
            ])
            ->assertRedirect();

        $employee = Employee::where('organization_id', $org->id)
            ->where('employee_code', 'EMP-NEW1')
            ->firstOrFail();

        $this->assertNotNull($employee->photo_path);
        Storage::disk('public')->assertExists($employee->photo_path);
    }
}
