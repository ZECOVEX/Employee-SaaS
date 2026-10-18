<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesOrganizations;
use Tests\TestCase;

class ThemeToggleTest extends TestCase
{
    use CreatesOrganizations;
    use RefreshDatabase;

    /**
     * Guest pages (login) apply the stored theme before paint so there is
     * no flash of the wrong mode.
     */
    public function test_guest_pages_initialize_the_theme_before_paint(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertSee("localStorage.getItem('theme')", false)
            ->assertSee('prefers-color-scheme: dark', false);
    }

    /**
     * The authenticated shell ships both the toggle controls and the
     * delegated click handler that flips the root .dark class and
     * persists the choice to localStorage.
     */
    public function test_app_shell_renders_the_theme_toggle_and_persists_the_choice(): void
    {
        $ctx = $this->makeOrganization('Theme Toggle Co');
        $user = $this->makeUser($ctx['organization'], 'company_admin');

        $this->actingAs($user)
            ->get(route('notifications.index'))
            ->assertOk()
            ->assertSee('class="app-theme-toggle', false)
            ->assertSee('__zecovexThemeToggleBound', false)
            ->assertSee("localStorage.setItem('theme'", false);
    }

    /**
     * The button swaps a moon (light mode) for a sun (dark mode) using
     * dark: variants, so both modes are reachable from the nav.
     */
    public function test_toggle_offers_both_modes_via_light_and_dark_icons(): void
    {
        $ctx = $this->makeOrganization('Theme Icons Co');
        $user = $this->makeUser($ctx['organization'], 'company_admin');

        $this->actingAs($user)
            ->get(route('notifications.index'))
            ->assertOk()
            ->assertSee('dark:hidden', false)
            ->assertSee('dark:block', false)
            ->assertSee('Toggle dark mode', false);
    }
}
