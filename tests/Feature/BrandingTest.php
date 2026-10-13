<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesOrganizations;
use Tests\TestCase;

class BrandingTest extends TestCase
{
    use CreatesOrganizations;
    use RefreshDatabase;

    public function test_landing_page_shows_powered_by_zecovex(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('Powered by Zecovex');
    }

    public function test_landing_page_is_the_product_marketing_page_not_the_laravel_default(): void
    {
        $response = $this->get('/');

        $response->assertOk()
            ->assertSee('Run your entire team from')
            ->assertSee('Trusted by teams worldwide')
            ->assertSee('Security &amp; compliance', false)
            ->assertSee('All systems operational')
            ->assertSee('Employee directory')
            ->assertSee('Attendance & live board')
            ->assertSee('Payroll & payslips')
            ->assertSee(route('login'))
            ->assertSee(route('register'))
            ->assertSee(route('privacy'));

        $this->assertStringNotContainsString('laravel.com', $response->getContent());
        $this->assertStringNotContainsString('Laracasts', $response->getContent());
    }

    public function test_dashboard_footer_shows_powered_by_with_company_name(): void
    {
        ['organization' => $org, 'roles' => $roles] = $this->makeOrganization();
        $admin = $this->makeUser($org, 'company_admin', $roles);

        $this->actingAs($admin)->get(route('dashboard.admin'))
            ->assertOk()
            ->assertSee('Powered by Zecovex | '.$org->name);
    }

    public function test_navbar_brand_shows_company_name_instead_of_product_name(): void
    {
        ['organization' => $org, 'roles' => $roles] = $this->makeOrganization();
        $admin = $this->makeUser($org, 'company_admin', $roles);

        $response = $this->actingAs($admin)->get(route('dashboard.admin'));

        $response->assertOk();
        $this->assertMatchesRegularExpression(
            '/class="font-semibold text-indigo-600 whitespace-nowrap">\s*'.preg_quote($org->name, '/').'\s*<\/a>/',
            $response->getContent()
        );
    }
}
