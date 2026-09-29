<?php

namespace Database\Seeders;

use App\Models\Organization;
use App\Models\User;
use App\Services\RoleSeederService;
use App\Services\WorkforceDefaults;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        app(RoleSeederService::class)->ensureCatalog();
        $this->call(PlanSeeder::class);

        $organization = Organization::firstOrCreate(
            ['slug' => 'demo-company'],
            [
                'name' => 'Demo Company',
                'timezone' => 'Asia/Dhaka',
                'status' => 'active',
            ],
        );

        $roleMap = app(RoleSeederService::class)->createForOrganization($organization);
        app(WorkforceDefaults::class)->apply($organization);

        $admin = User::firstOrCreate(
            ['email' => 'admin@demo.test'],
            [
                'organization_id' => $organization->id,
                'name' => 'Demo Admin',
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
            ],
        );
        $admin->roles()->syncWithoutDetaching([$roleMap['company_admin']->id]);

        $employeeUser = User::firstOrCreate(
            ['email' => 'employee@demo.test'],
            [
                'organization_id' => $organization->id,
                'name' => 'Demo Employee',
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
            ],
        );
        $employeeUser->roles()->syncWithoutDetaching([$roleMap['employee']->id]);

        // Second org to prove tenancy isolation in manual testing
        $other = Organization::firstOrCreate(
            ['slug' => 'other-company'],
            [
                'name' => 'Other Company',
                'timezone' => 'UTC',
                'status' => 'active',
            ],
        );
        $otherRoles = app(RoleSeederService::class)->createForOrganization($other);
        app(WorkforceDefaults::class)->apply($other);

        $otherAdmin = User::firstOrCreate(
            ['email' => 'admin@other.test'],
            [
                'organization_id' => $other->id,
                'name' => 'Other Admin',
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
            ],
        );
        $otherAdmin->roles()->syncWithoutDetaching([$otherRoles['company_admin']->id]);
    }
}
