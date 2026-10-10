<?php

namespace App\Services;

use App\Models\Department;
use App\Models\Employee;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class OrganizationProvisioner
{
    public function __construct(
        private readonly RoleSeederService $roles,
        private readonly WorkforceDefaults $defaults,
        private readonly BillingService $billing,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Create organization + roles + owner user (+ optional employee record).
     *
     * @param  array{
     *     organization_name: string,
     *     name: string,
     *     email: string,
     *     password: string,
     * }  $data
     */
    public function provision(array $data): array
    {
        return DB::transaction(function () use ($data) {
            $slug = Str::slug($data['organization_name']);
            if (Organization::where('slug', $slug)->exists()) {
                $slug .= '-'.Str::lower(Str::random(6));
            }

            $organization = Organization::create([
                'name' => $data['organization_name'],
                'slug' => $slug,
                'timezone' => 'Asia/Dhaka',
                'status' => 'active',
            ]);

            $roleMap = $this->roles->createForOrganization($organization);

            $user = User::create([
                'organization_id' => $organization->id,
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => Hash::make($data['password']),
                'email_verified_at' => now(),
            ]);

            $user->roles()->attach($roleMap['company_admin']);

            $department = Department::create([
                'organization_id' => $organization->id,
                'name' => 'General',
                'code' => 'GEN',
            ]);

            $employee = Employee::create([
                'organization_id' => $organization->id,
                'user_id' => $user->id,
                'employee_code' => 'EMP-001',
                'department_id' => $department->id,
                'status' => 'active',
                'joining_date' => now()->toDateString(),
            ]);

            $this->defaults->apply($organization);
            $this->billing->provisionDefault($organization);

            // §40/Phase 7: registration itself is a tenant-creating event —
            // audit it inside the transaction so a rollback never leaves a
            // log row for an org that does not exist.
            $this->audit->log('organization.registered', $organization, null, [
                'name' => $organization->name,
                'slug' => $organization->slug,
                'owner_name' => $data['name'],
                'owner_email' => $data['email'],
            ]);

            return [
                'organization' => $organization,
                'user' => $user,
                'employee' => $employee,
            ];
        });
    }
}
