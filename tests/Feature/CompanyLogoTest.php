<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesOrganizations;
use Tests\TestCase;

/**
 * Company logo (settings.manage): admins upload/remove it from Company
 * Settings; it renders beside the company name in the sidebar brand row.
 * The brand anchor's exact class string (BrandingTest regex) must survive â€”
 * the logo sits before the anchor, never inside it.
 */
class CompanyLogoTest extends TestCase
{
    use CreatesOrganizations;
    use RefreshDatabase;

    public function test_admin_can_upload_logo_and_it_shows_beside_the_company_name(): void
    {
        Storage::fake('public');

        $ctx = $this->makeOrganization('Logo Co '.uniqid());
        $admin = $this->makeUser($ctx['organization'], 'company_admin', $ctx['roles']);

        $response = $this->actingAs($admin)->call(
            'PUT',
            route('settings.update'),
            $this->payload($ctx['organization']->name),
            [],
            ['logo' => $this->png()]
        );

        $response->assertRedirect(route('settings.edit'));
        $response->assertSessionHasNoErrors();

        $ctx['organization']->refresh();
        $this->assertNotNull($ctx['organization']->logo_path);
        $this->assertStringStartsWith('organizations/'.$ctx['organization']->id.'/', $ctx['organization']->logo_path);
        Storage::disk('public')->assertExists($ctx['organization']->logo_path);

        $this->assertDatabaseHas('audit_logs', [
            'organization_id' => $ctx['organization']->id,
            'action' => 'organization.updated',
        ]);

        // The settings page previews the uploaded logo.
        $settings = $this->actingAs($admin)->get(route('settings.edit'));
        $settings->assertOk();
        $settings->assertSee(asset('storage/'.$ctx['organization']->logo_path), false);

        // Sidebar: logo image beside the name; the gradient mark is gone.
        $nav = $this->actingAs($admin)->get(route('dashboard.admin'));
        $nav->assertOk();
        $nav->assertSee('class="app-brand-logo"', false);
        $nav->assertSee(asset('storage/'.$ctx['organization']->logo_path), false);
        $nav->assertDontSee('app-brand-mark', false);

        $this->assertMatchesRegularExpression(
            '/class="font-semibold text-indigo-600 whitespace-nowrap">\s*'.preg_quote($ctx['organization']->name, '/').'\s*<\/a>/',
            $nav->getContent()
        );
    }

    public function test_employee_sees_company_logo_in_sidebar(): void
    {
        Storage::fake('public');

        $ctx = $this->makeOrganization('Logo Staff '.uniqid());
        $admin = $this->makeUser($ctx['organization'], 'company_admin', $ctx['roles']);
        $employee = $this->makeUser($ctx['organization'], 'employee', $ctx['roles']);

        $this->actingAs($admin)->call(
            'PUT',
            route('settings.update'),
            $this->payload($ctx['organization']->name),
            [],
            ['logo' => $this->png()]
        )->assertRedirect(route('settings.edit'));

        $nav = $this->actingAs($employee)->get(route('dashboard.employee'));
        $nav->assertOk();
        $nav->assertSee('class="app-brand-logo"', false);
        $nav->assertSee(asset('storage/'.$ctx['organization']->logo_path), false);
    }

    public function test_non_image_logo_is_rejected(): void
    {
        Storage::fake('public');

        $ctx = $this->makeOrganization('Logo Bad '.uniqid());
        $admin = $this->makeUser($ctx['organization'], 'company_admin', $ctx['roles']);

        $response = $this->actingAs($admin)->call(
            'PUT',
            route('settings.update'),
            $this->payload($ctx['organization']->name),
            [],
            ['logo' => UploadedFile::fake()->create('cv.pdf', 100, 'application/pdf')]
        );

        $response->assertSessionHasErrors('logo');
        $this->assertNull($ctx['organization']->fresh()->logo_path);
    }

    public function test_logo_can_be_removed(): void
    {
        Storage::fake('public');

        $ctx = $this->makeOrganization('Logo Rm '.uniqid());
        $admin = $this->makeUser($ctx['organization'], 'company_admin', $ctx['roles']);

        $this->actingAs($admin)->call(
            'PUT',
            route('settings.update'),
            $this->payload($ctx['organization']->name),
            [],
            ['logo' => $this->png()]
        )->assertRedirect(route('settings.edit'));

        $ctx['organization']->refresh();
        $oldPath = $ctx['organization']->logo_path;
        $this->assertNotNull($oldPath);

        $response = $this->actingAs($admin)->call(
            'PUT',
            route('settings.update'),
            $this->payload($ctx['organization']->name, ['remove_logo' => '1'])
        );

        $response->assertRedirect(route('settings.edit'));
        $this->assertNull($ctx['organization']->fresh()->logo_path);
        Storage::disk('public')->assertMissing($oldPath);

        $nav = $this->actingAs($admin)->get(route('dashboard.admin'));
        $nav->assertOk();
        $nav->assertDontSee('app-brand-logo', false);
        $nav->assertSee('app-brand-mark', false);
    }

    public function test_user_without_settings_permission_cannot_upload_logo(): void
    {
        Storage::fake('public');

        $ctx = $this->makeOrganization('Logo Perm '.uniqid());
        $employee = $this->makeUser($ctx['organization'], 'employee', $ctx['roles']);

        $response = $this->actingAs($employee)->call(
            'PUT',
            route('settings.update'),
            $this->payload($ctx['organization']->name),
            [],
            ['logo' => $this->png()]
        );

        $response->assertForbidden();
        $this->assertNull($ctx['organization']->fresh()->logo_path);
    }

    /**
     * Required Company Settings fields (the logo is the only optional extra).
     *
     * @return array<string, mixed>
     */
    private function payload(string $name, array $extra = []): array
    {
        return array_merge([
            'name' => $name,
            'timezone' => 'Asia/Dhaka',
            'debounce_seconds' => 10,
            'time_format' => '24h',
        ], $extra);
    }

    private function png(): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'logo').'.png';
        file_put_contents($path, base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='
        ));

        return new UploadedFile($path, 'logo.png', 'image/png', null, true);
    }
}
