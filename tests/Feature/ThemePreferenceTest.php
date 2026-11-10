<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\DarkThemeStylesheet;
use App\Theme;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ThemePreferenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_user_starts_in_the_light_theme_and_the_page_opens_with_it(): void
    {
        $user = User::factory()->create();

        $this->assertSame(Theme::Light, $user->fresh()->theme);
        $this->actingAs($user)->get('/')->assertOk()->assertSee('<html lang="pt-BR" data-theme="light">', false);
    }

    public function test_user_without_any_screen_or_permission_saves_the_dark_theme_and_the_page_opens_in_it(): void
    {
        $user = User::factory()->create(['screens' => [], 'can_update_items' => false, 'can_create_tickets' => false]);

        $this->actingAs($user)->postJson(route('theme.update'), ['theme' => 'dark'])->assertOk()->assertExactJson(['theme' => 'dark']);

        $this->assertSame(Theme::Dark, $user->fresh()->theme);
        $this->get('/')->assertOk()->assertSee('<html lang="pt-BR" data-theme="dark">', false);
    }

    public function test_each_user_keeps_their_own_theme(): void
    {
        [$dark, $light] = User::factory()->count(2)->create();

        $this->actingAs($dark)->postJson(route('theme.update'), ['theme' => 'dark'])->assertOk();

        $this->assertSame(Theme::Dark, $dark->fresh()->theme);
        $this->assertSame(Theme::Light, $light->fresh()->theme);
    }

    public function test_unknown_theme_is_refused_and_nothing_changes(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->postJson(route('theme.update'), ['theme' => 'blue'])
            ->assertUnprocessable()->assertJsonValidationErrors(['theme' => 'Tema inválido.']);

        $this->assertSame(Theme::Light, $user->fresh()->theme);
    }

    public function test_guest_cannot_change_the_theme(): void
    {
        $this->postJson(route('theme.update'), ['theme' => 'dark'])->assertUnauthorized();
    }

    public function test_dark_theme_stylesheet_is_up_to_date_with_the_dashboard_styles(): void
    {
        $committed = str_replace("\r\n", "\n", file_get_contents(public_path(DarkThemeStylesheet::OUTPUT)));

        $this->assertSame((new DarkThemeStylesheet)->generate(), $committed, 'Um CSS do dashboard mudou: rode "php artisan theme:dark" e suba o dark-theme.css junto.');
    }
}
