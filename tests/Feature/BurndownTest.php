<?php

namespace Tests\Feature;

use App\Models\User;
use App\Screen;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\SprintHistoryScenario;
use Tests\TestCase;

class BurndownTest extends TestCase
{
    use RefreshDatabase;
    use SprintHistoryScenario;

    public function test_burndown_requires_login_and_the_burndown_screen(): void
    {
        $this->getJson('/api/burndown')->assertUnauthorized();

        $this->actingAs(User::factory()->withScreens(Screen::ControlTower)->create());
        $this->getJson('/api/burndown')->assertForbidden();
        $this->get('/')->assertOk()->assertDontSee('nav-burndown', false);
    }

    public function test_dashboard_includes_the_burndown_page(): void
    {
        $this->actingAs(User::factory()->create())->get('/')->assertOk()
            ->assertSee('nav-burndown', false)->assertSee('burndown.js')->assertSee('Como os gráficos são calculados')->assertSee('SLA do suporte');
    }

    public function test_burndown_shows_remaining_done_and_scope_per_working_day_against_the_ideal_line(): void
    {
        $this->prepareScenario();

        $response = $this->getJson('/api/burndown')->assertOk()
            ->assertJsonPath('sprint', 17)
            ->assertJsonPath('available', true)
            ->assertJsonPath('workingDays', 10)
            ->assertJsonPath('remainingDays', 3)
            ->assertJsonCount(10, 'days')
            ->assertJsonPath('panels.*.key', ['all', 'support', 'other'])
            ->assertJsonCount(8, 'panels.0.points')
            ->assertJsonPath('panels.0.summary.initialScope', 3)
            ->assertJsonPath('panels.0.summary.scope', 5)
            ->assertJsonPath('panels.0.summary.done', 1)
            ->assertJsonPath('panels.0.summary.remaining', 4)
            ->assertJsonPath('panels.0.summary.requiredPace', 1.3);

        $points = collect($response->json('panels.0.points'))->keyBy('date');
        $this->assertSame(['scope' => 3, 'done' => 0, 'remaining' => 3, 'scopeChange' => 0], collect($points['2026-09-28'])->only(['scope', 'done', 'remaining', 'scopeChange'])->all());
        $this->assertSame(1, $points['2026-10-02']['scopeChange']);
        $this->assertSame(4, $points['2026-10-07']['remaining']);

        $ideal = $response->json('panels.0.ideal.cards');
        $this->assertCount(10, $ideal);
        $this->assertEquals(3, $ideal[0]);
        $this->assertEquals(0, $ideal[9]);
        $this->assertEquals(1.67, $ideal[4]);
    }

    public function test_support_and_other_panels_split_the_sprint_by_card_origin(): void
    {
        $this->prepareScenario();

        $response = $this->getJson('/api/burndown')->assertOk();

        $support = collect($response->json('panels.1.points'))->keyBy('date');
        $other = collect($response->json('panels.2.points'))->keyBy('date');
        $this->assertSame([0, 1], [$support['2026-10-02']['scope'], $support['2026-10-07']['scope']]);
        $this->assertSame(1, $support['2026-10-05']['scopeChange']);
        $this->assertSame(['scope' => 4, 'done' => 1, 'remaining' => 3], collect($other['2026-10-07'])->only(['scope', 'done', 'remaining'])->all());
        $this->assertEquals(16, $other['2026-10-07']['scopeHours']);
        $this->assertSame(0, $response->json('panels.1.ideal.start'));
    }

    public function test_support_sla_measures_from_card_creation_to_implemented(): void
    {
        $snapshot = $this->scenarioSnapshot();
        $snapshot['rows'][5]['Status'] = 'Implementado';
        $snapshot['rows'][] = [...$snapshot['rows'][5], 'Id' => '8', 'Nome' => 'Card 8', 'Status' => 'Em progresso'];
        $this->prepareScenario($snapshot);
        $this->historyEvent('6', null, null, null, '2026-10-05 09:00', 'create_pulse');
        $this->historyEvent('6', 'status', ['label' => ['text' => 'Em progresso']], ['label' => ['text' => 'Implementado']], '2026-10-06 09:00');
        $this->historyEvent('8', null, null, null, '2026-10-06 12:00', 'create_pulse');

        $sla = $this->getJson('/api/burndown')->assertOk()->json('sla');

        $this->assertSame(1, $sla['done']);
        $this->assertEquals(24, $sla['averageHours']);
        $this->assertEquals(24, $sla['medianHours']);
        $this->assertSame(1, $sla['open']);
        $this->assertEquals(24, $sla['openAverageHours']);
        $this->assertSame(0, $sla['unknown']);
        $this->assertSame(['6', '8'], collect($sla['items'])->pluck('id')->sort()->values()->all());
    }

    public function test_support_cards_created_before_the_copied_history_have_unknown_sla(): void
    {
        $this->prepareScenario();

        $sla = $this->getJson('/api/burndown')->assertOk()->json('sla');

        $this->assertSame(1, $sla['unknown']);
        $this->assertNull($sla['averageHours']);
        $this->assertNull($sla['items'][0]['hours']);
    }

    public function test_burndown_accepts_a_past_sprint_and_rejects_invalid_numbers(): void
    {
        $this->prepareScenario();

        $this->getJson('/api/burndown?sprint=16')->assertOk()->assertJsonPath('sprint', 16)->assertJsonPath('remainingDays', 0);
        $this->getJson('/api/burndown?sprint=abc')->assertUnprocessable()->assertJsonValidationErrors('sprint');
    }

    /** @param  array<string, mixed>|null  $snapshot */
    private function prepareScenario(?array $snapshot = null): void
    {
        $this->travelTo('2026-10-07 15:00:00');
        $this->actingAs(User::factory()->create());
        $this->configureScenarioConnection();
        $this->seedScenarioHistory();
        Cache::put('monday-sync-1', $snapshot ?? $this->scenarioSnapshot(), 3600);
        Http::preventStrayRequests();
        Http::fake(['api.monday.com/v2' => Http::response(['data' => ['boards' => [['activity_logs' => []]]]])]);
    }
}
