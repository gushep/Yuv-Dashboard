<?php

namespace Tests\Feature;

use App\Models\User;
use App\Screen;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ScrumIndicatorsTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_the_scrum_master_with_the_screen_sees_the_scrum_indicators(): void
    {
        $this->actingAs(User::factory()->scrumMaster()->withScreens(Screen::ScrumIndicators)->create())->get('/indicadores-scrum')->assertOk()
            ->assertSee('id="nav-scrum-indicators"', false)->assertSee('id="page-scrum-indicators"', false)->assertSee('scrum-indicators.js', false);

        $this->actingAs(User::factory()->withScreens(Screen::ScrumIndicators, Screen::Dashboard)->create())->get('/')->assertOk()
            ->assertDontSee('id="nav-scrum-indicators"', false)->assertDontSee('id="page-scrum-indicators"', false);

        $this->actingAs(User::factory()->scrumMaster()->withScreens(Screen::Dashboard)->create())->get('/')->assertOk()
            ->assertDontSee('id="nav-scrum-indicators"', false);
    }

    public function test_the_screen_address_is_blocked_for_who_is_not_scrum_master(): void
    {
        $this->actingAs(User::factory()->withScreens(Screen::ScrumIndicators, Screen::Dashboard)->create())->get('/indicadores-scrum')
            ->assertDontSee('id="page-scrum-indicators"', false);
    }

    public function test_every_scrum_master_gets_the_scrum_indicators_screen(): void
    {
        $scrumMaster = User::factory()->scrumMaster()->withScreens(Screen::Planning)->create();
        $developer = User::factory()->withScreens(Screen::Planning)->create();
        $migration = require database_path('migrations/2026_11_17_181327_grant_scrum_indicators_screen_to_scrum_masters.php');

        $migration->up();
        $migration->up();

        $screens = fn (User $user): array => json_decode(DB::table('users')->where('id', $user->id)->value('screens'), true);
        $this->assertSame(['planning', 'scrum-indicators'], $screens($scrumMaster));
        $this->assertSame(['planning'], $screens($developer));
    }
}
