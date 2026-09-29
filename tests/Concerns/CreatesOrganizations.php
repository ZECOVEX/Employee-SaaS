<?php

namespace Tests\Concerns;

use App\Models\Department;
use App\Models\Employee;
use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use App\Services\RoleSeederService;
use Database\Seeders\PlanSeeder;

trait CreatesOrganizations
{
    /**
     * @return array{organization: Organization, roles: array<string, Role>}
     */
    protected function makeOrganization(string $name = 'Acme Inc'): array
    {
        $organization = Organization::create([
            'name' => $name,
            'slug' => str($name)->slug()->toString().'-'.uniqid(),
            'timezone' => 'UTC',
            'status' => 'active',
        ]);

        $roles = app(RoleSeederService::class)->createForOrganization($organization);

        return ['organization' => $organization, 'roles' => $roles];
    }

    /**
     * @param  array<string, Role>|null  $roles
     */
    protected function makeUser(Organization $organization, string $roleSlug, ?array $roles = null, array $attrs = []): User
    {
        $roleMap = $roles ?? app(RoleSeederService::class)->createForOrganization($organization);

        $user = User::create([
            'organization_id' => $organization->id,
            'name' => $attrs['name'] ?? ucfirst($roleSlug).' User',
            'email' => $attrs['email'] ?? $roleSlug.'-'.uniqid().'@example.test',
            'password' => $attrs['password'] ?? 'password',
            'is_platform_admin' => $attrs['is_platform_admin'] ?? false,
        ]);

        // email_verified_at is intentionally not mass-assignable.
        $user->forceFill(['email_verified_at' => $attrs['email_verified_at'] ?? now()])->save();

        if (isset($roleMap[$roleSlug])) {
            $user->roles()->attach($roleMap[$roleSlug]->id);
        }

        // Do not preload roles here: Role's organization global scope sees no
        // Auth user during setup and would cache an empty relation on the model.
        return $user->unsetRelation('roles');
    }

    protected function makeEmployee(Organization $organization, User $user, string $code = 'EMP-100'): Employee
    {
        return Employee::create([
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'employee_code' => $code,
            'status' => 'active',
            'employment_type' => 'full_time',
            'joining_date' => now()->toDateString(),
        ]);
    }

    protected function makeDepartment(Organization $organization, string $name = 'Engineering'): Department
    {
        return Department::create([
            'organization_id' => $organization->id,
            'name' => $name,
            'code' => strtoupper(substr($name, 0, 3)),
        ]);
    }

    /** Load the platform plan catalog (§54) — billing tests only. */
    protected function seedPlans(): void
    {
        $this->seed(PlanSeeder::class);
    }
}
