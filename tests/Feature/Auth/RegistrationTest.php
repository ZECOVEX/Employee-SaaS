<?php

namespace Tests\Feature\Auth;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesOrganizations;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use CreatesOrganizations, RefreshDatabase;

    public function test_registration_screen_can_be_rendered(): void
    {
        $this->get('/register')
            ->assertOk()
            ->assertSee('organization_name');
    }

    public function test_new_organization_can_be_registered(): void
    {
        $response = $this->post('/register', [
            'organization_name' => 'Spark Ltd',
            'name' => 'Owner',
            'email' => 'owner@spark.test',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $response->assertRedirect(route('dashboard', absolute: false));
        $this->assertAuthenticated();

        $organization = Organization::where('slug', 'like', 'spark-ltd%')->firstOrFail();
        $user = User::where('email', 'owner@spark.test')->firstOrFail();

        $this->assertSame($organization->id, $user->organization_id);
        $this->assertTrue($user->hasRole('company_admin'));
        $this->assertDatabaseHas('departments', [
            'organization_id' => $organization->id,
            'name' => 'General',
        ]);
        $this->assertDatabaseHas('employees', [
            'organization_id' => $organization->id,
            'user_id' => $user->id,
        ]);
    }

    public function test_registration_requires_fields(): void
    {
        $this->post('/register', [])
            ->assertSessionHasErrors([
                'organization_name',
                'name',
                'email',
                'password',
            ]);
    }

    public function test_registration_rejects_duplicate_email(): void
    {
        $existing = $this->makeOrganization();
        $this->makeUser($existing['organization'], 'employee', $existing['roles'], [
            'email' => 'taken@example.test',
        ]);

        $this->post('/register', [
            'organization_name' => 'Dup Co',
            'name' => 'Dup',
            'email' => 'taken@example.test',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertSessionHasErrors('email');
    }
}
