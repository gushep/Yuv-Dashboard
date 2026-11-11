<?php

namespace Tests\Feature;

use App\Models\User;
use App\Screen;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ScreenRoutesTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_screen_has_its_own_address_that_opens_the_dashboard(): void
    {
        $this->actingAs(User::factory()->create());

        foreach (Screen::cases() as $screen) {
            $this->get('/'.$screen->slug())->assertOk()->assertSee('SCREEN_SLUGS', false);
        }
        $this->get('/sprints/18')->assertOk();
        $this->get('/torre-de-controle?sprint=18')->assertOk();
    }

    public function test_scripts_and_styles_carry_their_version_so_the_cdn_serves_the_new_files_after_a_deploy(): void
    {
        $this->actingAs(User::factory()->create());

        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringContainsString(asset('users.js').'?v='.filemtime(public_path('users.js')), $html);
        $this->assertStringContainsString(asset('item-detail.css').'?v='.filemtime(public_path('item-detail.css')), $html);
        $this->assertDoesNotMatchRegularExpression('#(src|href)="'.preg_quote(asset(''), '#').'[^"?]+\.(js|css)"#', $html);
    }

    public function test_tasks_screen_offers_the_roadmap_backlog_current_sprint_and_support_tabs_in_flow_order(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get('/tarefas')->assertOk()
            ->assertSeeInOrder(["chooseRawTab('roadmap')", "chooseRawTab('backlog')", "chooseRawTab('tarefas')", "chooseRawTab('suporte')"], false)
            ->assertSee('Sprint atual');
    }

    public function test_old_raw_data_address_redirects_to_the_tasks_screen(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get('/dados')->assertRedirect('/tarefas');
        $this->get('/dados?sprint=18')->assertRedirect('/tarefas?sprint=18');
        $this->get('/tarefas')->assertOk()->assertSee('<span class="nav-label">Tarefas</span>', false);
    }

    public function test_agile_management_menu_groups_sprints_sprint_planning_and_the_scrum_events_under_construction(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get('/')->assertOk()
            ->assertSeeInOrder(['Gerenciamento Ágil', "navigate('sprint')", 'Sprints', "navigate('planning')", 'Sprint Planning', "navigate('daily-scrum')", 'Daily Scrum', "navigate('sprint-review')", 'Sprint Review', "navigate('sprint-retrospective')", 'Sprint Retrospective'], false)
            ->assertSee('id="page-daily-scrum"', false)->assertSee('id="page-sprint-review"', false)->assertSee('id="page-sprint-retrospective"', false)
            ->assertSee('Em construção')
            ->assertDontSee('<span class="nav-label">Planning poker</span>', false);
    }

    public function test_agile_management_menu_shows_only_the_screens_of_the_user(): void
    {
        $this->actingAs(User::factory()->withScreens(Screen::Dashboard, Screen::Sprints)->create())->get('/')->assertOk()
            ->assertSee('id="nav-group-agile"', false)->assertSee('id="nav-sprint"', false)
            ->assertDontSee('id="nav-planning"', false)->assertDontSee('id="nav-daily-scrum"', false);

        $this->actingAs(User::factory()->withScreens(Screen::Dashboard)->create())->get('/')->assertOk()
            ->assertDontSee('id="nav-group-agile"', false);
    }

    public function test_new_agile_screens_are_granted_to_who_already_sees_sprints_or_sprint_planning(): void
    {
        $planner = User::factory()->withScreens(Screen::Planning)->create();
        $viewer = User::factory()->withScreens(Screen::Dashboard)->create();
        $migration = require database_path('migrations/2026_11_11_183208_grant_agile_screens_to_users.php');

        $migration->up();

        $this->assertTrue($planner->fresh()->canAccessScreen(Screen::DailyScrum));
        $this->assertTrue($planner->fresh()->canAccessScreen(Screen::SprintReview));
        $this->assertTrue($planner->fresh()->canAccessScreen(Screen::SprintRetrospective));
        $this->assertFalse($viewer->fresh()->canAccessScreen(Screen::DailyScrum));

        $migration->down();
        $this->assertFalse($planner->fresh()->canAccessScreen(Screen::DailyScrum));
        $this->assertTrue($planner->fresh()->canAccessScreen(Screen::Planning));
    }

    public function test_every_screen_has_a_guided_tour_opened_from_the_top_bar(): void
    {
        $tours = (string) file_get_contents(public_path('screen-tours.js'));
        preg_match_all("/^\s{4}'?([\w-]+)'?: \{title: /m", $tours, $matches);

        $this->assertEqualsCanonicalizing(Screen::values(), $matches[1], 'Toda tela de App\Screen precisa de um tour em public/screen-tours.js.');
        $this->actingAs(User::factory()->create())->get('/')->assertOk()
            ->assertSee('id="tb-tour"', false)->assertSee('Tour.startScreen()', false)->assertSee('screen-tours.js');
    }

    public function test_screen_addresses_require_login_and_unknown_addresses_are_not_found(): void
    {
        $this->get('/burndown')->assertRedirect('/login');

        $this->actingAs(User::factory()->create());
        $this->get('/tela-inexistente')->assertNotFound();
        $this->get('/sprints/abc')->assertNotFound();
    }

    public function test_login_and_dashboard_point_to_the_app_manifest_so_the_site_can_be_installed(): void
    {
        $this->get('/login')->assertOk()->assertSee(asset('manifest.json'), false)->assertSee('/sw.js', false);
        $this->actingAs(User::factory()->create())->get('/')->assertOk()->assertSee(asset('manifest.json'), false);

        $manifest = json_decode((string) file_get_contents(public_path('manifest.json')), true);
        $this->assertSame('standalone', $manifest['display']);
        foreach ($manifest['icons'] as $icon) {
            $this->assertFileExists(public_path(ltrim($icon['src'], '/')));
        }
    }

    public function test_android_app_is_verified_by_the_digital_asset_links_file(): void
    {
        $links = json_decode((string) file_get_contents(public_path('.well-known/assetlinks.json')), true);

        $this->assertSame('br.com.yuv.metrics', $links[0]['target']['package_name']);
        $this->assertSame(['delegate_permission/common.handle_all_urls'], $links[0]['relation']);
    }

    public function test_menu_offers_the_android_app_download_only_when_the_apk_was_published(): void
    {
        $apk = public_path('downloads/yuv-sprint-metrics.apk');
        $existing = is_file($apk) ? file_get_contents($apk) : null;
        $this->actingAs(User::factory()->create());

        try {
            @unlink($apk);
            $this->get('/')->assertOk()->assertDontSee('Baixar app Android');

            file_put_contents($apk, 'apk');
            $this->get('/')->assertOk()->assertSee('Baixar app Android')
                ->assertSee(asset('downloads/yuv-sprint-metrics.apk').'?v='.filemtime($apk), false);
        } finally {
            $existing === null ? @unlink($apk) : file_put_contents($apk, $existing);
        }
    }

    public function test_assets_use_absolute_paths_so_nested_addresses_load_them(): void
    {
        $this->actingAs(User::factory()->create())->get('/sprints/18')->assertOk()
            ->assertSee(asset('control-tower.js'), false)
            ->assertDontSee('src="control-tower.js"', false)
            ->assertDontSee('href="bombeiro.css"', false);
    }
}
