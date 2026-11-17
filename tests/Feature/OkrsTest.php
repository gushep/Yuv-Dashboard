<?php

namespace Tests\Feature;

use App\Models\User;
use App\Screen;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class OkrsTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_okrs_screen_shows_up_only_for_who_has_it(): void
    {
        $this->actingAs(User::factory()->withScreens(Screen::Dashboard)->create())->get('/')->assertOk()
            ->assertDontSee('id="nav-okrs"', false);

        $this->actingAs(User::factory()->withScreens(Screen::Okrs)->create())->get('/okrs')->assertOk()
            ->assertSee('id="nav-okrs"', false)->assertSee('id="page-okrs"', false)->assertSee('okrs.js', false);
    }

    public function test_whoever_sees_the_dashboard_gets_the_okrs_screen(): void
    {
        $withDashboard = User::factory()->withScreens(Screen::Dashboard, Screen::Planning)->create();
        $without = User::factory()->withScreens(Screen::Planning)->create();
        $migration = require database_path('migrations/2026_11_17_131135_grant_okrs_screen_to_users.php');

        $migration->up();
        $migration->up();

        $screens = fn (User $user): array => json_decode(DB::table('users')->where('id', $user->id)->value('screens'), true);
        $this->assertSame(['dashboard', 'planning', 'okrs'], $screens($withDashboard));
        $this->assertSame(['planning'], $screens($without));
    }
}
