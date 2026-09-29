<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\DailyAttendance;
use App\Models\LeaveType;
use App\Models\SalaryRecord;
use App\Models\User;
use App\Notifications\PasswordChanged;
use App\Services\WorkforceDefaults;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesOrganizations;
use Tests\TestCase;

class ApiV1Test extends TestCase
{
    use CreatesOrganizations, RefreshDatabase;

    public function test_login_me_logout_round_trip(): void
    {
        ['organization' => $org, 'roles' => $roles] = $this->makeOrganization('API Co');
        $admin = $this->makeUser($org, 'company_admin', $roles);

        $token = $this->apiLogin($admin);

        $this->withToken($token)
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.email', $admin->email)
            ->assertJsonPath('data.roles.0', 'company_admin');

        $this->withToken($token)->postJson('/api/v1/auth/logout')->assertOk();

        $this->withToken($token)->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_login_rejects_invalid_credentials(): void
    {
        ['organization' => $org, 'roles' => $roles] = $this->makeOrganization('Bad Login Co');
        $admin = $this->makeUser($org, 'company_admin', $roles);

        $this->postJson('/api/v1/auth/login', [
            'email' => $admin->email,
            'password' => 'wrong-password',
            'device_name' => 'phpunit',
        ])->assertStatus(401);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'ghost@example.test',
            'password' => 'password',
            'device_name' => 'phpunit',
        ])->assertStatus(401);

        $this->postJson('/api/v1/auth/login', [
            'email' => $admin->email,
            'password' => 'password',
        ])->assertStatus(422);
    }

    public function test_protected_endpoints_require_authentication(): void
    {
        $this->getJson('/api/v1/auth/me')->assertUnauthorized();
        $this->getJson('/api/v1/employees')->assertUnauthorized();
        $this->getJson('/api/v1/attendance/mine')->assertUnauthorized();
        $this->postJson('/api/v1/leave', [])->assertUnauthorized();
    }

    public function test_employee_endpoints_respect_permissions_and_tenancy(): void
    {
        ['organization' => $orgA, 'roles' => $rolesA] = $this->makeOrganization('Alpha API');
        ['organization' => $orgB, 'roles' => $rolesB] = $this->makeOrganization('Beta API');

        $adminA = $this->makeUser($orgA, 'company_admin', $rolesA);
        $employeeA = $this->makeEmployee($orgA, $this->makeUser($orgA, 'employee', $rolesA), 'EMP-A1');

        $employeeBUser = $this->makeUser($orgB, 'employee', $rolesB);
        $employeeB = $this->makeEmployee($orgB, $employeeBUser, 'EMP-B1');

        $tokenA = $this->apiLogin($adminA);
        $tokenB = $this->apiLogin($employeeBUser);

        $this->withToken($tokenA)
            ->getJson('/api/v1/employees')
            ->assertOk()
            ->assertJsonFragment(['employee_code' => 'EMP-A1'])
            ->assertJsonMissing(['employee_code' => 'EMP-B1']);

        // Plain employees lack employees.view.
        $this->withToken($tokenB)->getJson('/api/v1/employees')->assertForbidden();

        // Own-tenant show works; cross-tenant show is a 404 (org scope on binding).
        $this->withToken($tokenA)->getJson('/api/v1/employees/'.$employeeA->id)->assertOk();
        $this->withToken($tokenA)->getJson('/api/v1/employees/'.$employeeB->id)->assertNotFound();
    }

    public function test_departments_endpoint_requires_permission_and_is_tenant_scoped(): void
    {
        ['organization' => $orgA, 'roles' => $rolesA] = $this->makeOrganization('Dept A');
        ['organization' => $orgB, 'roles' => $rolesB] = $this->makeOrganization('Dept B');
        $this->makeDepartment($orgA, 'Engineering');
        $this->makeDepartment($orgB, 'Design');

        $adminA = $this->makeUser($orgA, 'company_admin', $rolesA);
        $employeeB = $this->makeUser($orgB, 'employee', $rolesB);

        $this->withToken($this->apiLogin($employeeB))
            ->getJson('/api/v1/departments')
            ->assertForbidden();

        $this->withToken($this->apiLogin($adminA))
            ->getJson('/api/v1/departments')
            ->assertOk()
            ->assertJsonFragment(['name' => 'Engineering'])
            ->assertJsonMissing(['name' => 'Design']);
    }

    public function test_attendance_endpoints(): void
    {
        ['organization' => $org, 'roles' => $roles] = $this->makeOrganization('Attendance API');
        $admin = $this->makeUser($org, 'company_admin', $roles);
        $employeeUser = $this->makeUser($org, 'employee', $roles);
        $employee = $this->makeEmployee($org, $employeeUser, 'EMP-AT1');

        DailyAttendance::create([
            'organization_id' => $org->id,
            'employee_id' => $employee->id,
            'date' => now()->toDateString(),
            'status' => 'PRESENT',
            'first_check_in' => now()->setTime(9, 0),
            'last_check_out' => now()->setTime(18, 0),
            'total_work_minutes' => 540,
            'late_minutes' => 0,
        ]);

        $employeeToken = $this->apiLogin($employeeUser);
        $adminToken = $this->apiLogin($admin);

        // Own records need no permission.
        $this->withToken($employeeToken)
            ->getJson('/api/v1/attendance/mine')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.employee_id', $employee->id);

        // Aggregated/events lists require attendance.view (employees lack it).
        $this->withToken($employeeToken)->getJson('/api/v1/attendance')->assertForbidden();
        $this->withToken($employeeToken)->getJson('/api/v1/attendance/events')->assertForbidden();

        $this->withToken($adminToken)
            ->getJson('/api/v1/attendance')
            ->assertOk()
            ->assertJsonCount(1, 'data');
        $this->withToken($adminToken)
            ->getJson('/api/v1/attendance/events')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_leave_flow_via_api(): void
    {
        ['organization' => $org, 'roles' => $roles] = $this->makeOrganization('Leave API');
        app(WorkforceDefaults::class)->apply($org);

        $employeeUser = $this->makeUser($org, 'employee', $roles);
        $this->makeEmployee($org, $employeeUser, 'EMP-LV1');

        $type = LeaveType::withoutGlobalScopes()
            ->where('organization_id', $org->id)
            ->where('is_active', true)
            ->firstOrFail();

        $token = $this->apiLogin($employeeUser);
        $start = now()->addWeek();

        // Validation errors surface as 422 JSON.
        $this->withToken($token)
            ->postJson('/api/v1/leave', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['leave_type_id', 'start_date', 'end_date']);

        $response = $this->withToken($token)->postJson('/api/v1/leave', [
            'leave_type_id' => $type->id,
            'start_date' => $start->toDateString(),
            'end_date' => $start->copy()->addDays(2)->toDateString(),
            'reason' => 'API leave request',
        ]);

        $response->assertStatus(201)->assertJsonPath('data.status', 'PENDING');

        $this->withToken($token)
            ->getJson('/api/v1/leave/mine')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        // Employees hold leave.view but only ever see their own requests.
        $this->withToken($token)
            ->getJson('/api/v1/leave')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_notifications_api_is_scoped_to_the_caller_and_marks_read(): void
    {
        ['organization' => $org, 'roles' => $roles] = $this->makeOrganization('Notify API');
        $userA = $this->makeUser($org, 'employee', $roles);
        $userB = $this->makeUser($org, 'employee', $roles);

        $userA->notify(new PasswordChanged);
        $userA->notify(new PasswordChanged);
        $userB->notify(new PasswordChanged);

        $tokenA = $this->apiLogin($userA);
        $tokenB = $this->apiLogin($userB);

        $listing = $this->withToken($tokenA)->getJson('/api/v1/notifications')->assertOk();
        $listing->assertJsonCount(2, 'data');
        $firstId = $listing->json('data.0.id');

        $read = $this->withToken($tokenA)->postJson("/api/v1/notifications/{$firstId}/read")->assertOk();
        $this->assertNotNull($read->json('data.read_at'));

        // Another user's notification cannot be read.
        $this->withToken($tokenB)->postJson("/api/v1/notifications/{$firstId}/read")->assertNotFound();

        $this->withToken($tokenA)->postJson('/api/v1/notifications/read-all')->assertOk();

        $after = $this->withToken($tokenA)->getJson('/api/v1/notifications')->assertOk();
        $this->assertEmpty(
            collect($after->json('data'))->whereNull('read_at')->values()->all(),
        );

        // B's notification is untouched.
        $bListing = $this->withToken($tokenB)->getJson('/api/v1/notifications')->assertOk();
        $bListing->assertJsonCount(1, 'data');
        $this->assertNull($bListing->json('data.0.read_at'));
    }

    public function test_salary_mine_returns_own_record_and_enforces_permissions(): void
    {
        ['organization' => $org, 'roles' => $roles] = $this->makeOrganization('Salary API');
        $admin = $this->makeUser($org, 'company_admin', $roles);
        $employeeUser = $this->makeUser($org, 'employee', $roles);
        $employee = $this->makeEmployee($org, $employeeUser, 'EMP-SA1');

        $amounts = SalaryRecord::computeAmounts(5000, 500, 0, 200);
        SalaryRecord::create([
            'organization_id' => $org->id,
            'employee_id' => $employee->id,
            'basic_salary' => 5000,
            'allowances' => 500,
            'bonus' => 0,
            'deductions' => 200,
            ...$amounts,
            'effective_from' => now()->subMonths(2)->toDateString(),
            'created_by' => $admin->id,
        ]);

        $response = $this->withToken($this->apiLogin($employeeUser))
            ->getJson('/api/v1/salary/mine')
            ->assertOk();
        $this->assertEquals(5300, $response->json('data.net_salary'));

        // Managers hold neither salary.view nor salary.view_own.
        $manager = $this->makeUser($org, 'manager', $roles);
        $this->withToken($this->apiLogin($manager))
            ->getJson('/api/v1/salary/mine')
            ->assertForbidden();

        // An employee without a record gets 404 (permission passes first).
        $noRecord = $this->makeUser($org, 'employee', $roles);
        $this->makeEmployee($org, $noRecord, 'EMP-SA2');
        $this->withToken($this->apiLogin($noRecord))
            ->getJson('/api/v1/salary/mine')
            ->assertNotFound();
    }

    public function test_audit_logs_endpoint_requires_permission_and_stays_tenant_scoped(): void
    {
        ['organization' => $orgA, 'roles' => $rolesA] = $this->makeOrganization('Audit A');
        ['organization' => $orgB, 'roles' => $rolesB] = $this->makeOrganization('Audit B');
        $adminA = $this->makeUser($orgA, 'company_admin', $rolesA);
        $employeeA = $this->makeUser($orgA, 'employee', $rolesA);

        AuditLog::create([
            'organization_id' => $orgA->id,
            'actor_user_id' => $adminA->id,
            'action' => 'employee.created',
            'ip_address' => '127.0.0.1',
            'user_agent' => 'phpunit',
        ]);
        AuditLog::create([
            'organization_id' => $orgB->id,
            'action' => 'b.only.action',
            'ip_address' => '127.0.0.1',
            'user_agent' => 'phpunit',
        ]);

        $this->withToken($this->apiLogin($employeeA))
            ->getJson('/api/v1/audit-logs')
            ->assertForbidden();

        $this->withToken($this->apiLogin($adminA))
            ->getJson('/api/v1/audit-logs')
            ->assertOk()
            ->assertJsonFragment(['action' => 'employee.created'])
            ->assertJsonMissing(['action' => 'b.only.action']);

        $this->withToken($this->apiLogin($adminA))
            ->getJson('/api/v1/audit-logs?action=employee.created')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_suspended_organizations_cannot_use_the_api(): void
    {
        ['organization' => $org, 'roles' => $roles] = $this->makeOrganization('Suspended API');
        $admin = $this->makeUser($org, 'company_admin', $roles);

        $token = $this->apiLogin($admin);

        $org->update(['status' => 'suspended']);

        // Existing tokens are blocked...
        $this->withToken($token)->getJson('/api/v1/auth/me')->assertForbidden();

        // ...and new logins are refused.
        $this->postJson('/api/v1/auth/login', [
            'email' => $admin->email,
            'password' => 'password',
            'device_name' => 'phpunit',
        ])->assertForbidden();
    }

    /**
     * Sanctum resolves tokens through a RequestGuard that caches the user on
     * the shared application instance. Tests reuse one app across requests,
     * so drop the guards before each call to re-authenticate from the token.
     */
    public function withToken(string $token, string $type = 'Bearer')
    {
        $this->app['auth']->forgetGuards();

        return parent::withToken($token, $type);
    }

    private function apiLogin(User $user, string $password = 'password'): string
    {
        $response = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => $password,
            'device_name' => 'phpunit',
        ]);

        $response->assertOk();

        return $response->json('data.token');
    }
}
