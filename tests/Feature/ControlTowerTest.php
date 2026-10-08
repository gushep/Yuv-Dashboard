<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\SprintHistoryScenario;
use Tests\TestCase;

class ControlTowerTest extends TestCase
{
    use RefreshDatabase;
    use SprintHistoryScenario;

    public function test_control_tower_requires_login(): void
    {
        $this->getJson('/api/control-tower')->assertUnauthorized();
    }

    public function test_dashboard_includes_the_control_tower_page_and_alert_badge(): void
    {
        $this->actingAs(User::factory()->create())->get('/')->assertOk()
            ->assertSee('nav-control-tower', false)->assertSee('tower-badge', false)
            ->assertSee('control-tower.js')->assertSee('Torre de controle');
    }

    public function test_control_tower_shows_the_current_sprint_trajectory_signals_and_alerts(): void
    {
        $this->prepareScenario();

        $response = $this->getJson('/api/control-tower')->assertOk()
            ->assertJsonPath('sprint', 17)
            ->assertJsonPath('available', true)
            ->assertJsonPath('remainingDays', 3)
            ->assertJsonPath('totals.scope', 5)
            ->assertJsonPath('totals.done', 1)
            ->assertJsonPath('totals.committed', 3)
            ->assertJsonPath('totals.committedDone', 1)
            ->assertJsonPath('status', 'red')
            ->assertJsonPath('decision.late', true)
            ->assertJsonCount(8, 'burnup')
            ->assertJsonPath('totals.categories.planned', ['scope' => 3, 'done' => 1, 'doneNotDelivered' => 0, 'remaining' => 2])
            ->assertJsonPath('totals.categories.unplanned', ['scope' => 1, 'done' => 0, 'doneNotDelivered' => 0, 'remaining' => 1])
            ->assertJsonPath('totals.categories.support', ['scope' => 1, 'done' => 0, 'doneNotDelivered' => 0, 'remaining' => 1]);
        $this->assertSame(['1' => 'planned', '4' => 'planned', '5' => 'planned', '6' => 'support', '7' => 'unplanned'],
            collect($response->json('items'))->pluck('category', 'id')->sortKeys()->all());

        $signals = collect($response->json('signals'))->keyBy('key');
        $this->assertSame('red', $signals['scope']['level']);
        $this->assertStringContainsString('1 card(s) fora do suporte', $signals['scope']['detail']);
        $this->assertSame('yellow', $signals['stalled']['level']);
        $this->assertSame('yellow', $signals['blocked']['level']);
        $this->assertSame('green', $signals['support']['level']);
        $this->assertSame('green', $signals['capacity']['level']);
        $this->assertEquals(8, $response->json('capacity.remainingHours'));
        $alertKeys = collect($response->json('alerts'))->pluck('key');
        $this->assertTrue($alertKeys->contains('stalled:4:2026-10-01'));
        $this->assertTrue($alertKeys->contains('blocked:5:2026-10-06'));
        $response->assertDontSee('token-secreto');
    }

    public function test_control_tower_accepts_a_past_sprint_and_rejects_invalid_numbers(): void
    {
        $this->prepareScenario();

        $this->getJson('/api/control-tower?sprint=16')->assertOk()->assertJsonPath('sprint', 16)->assertJsonPath('remainingDays', 0);
        $this->getJson('/api/control-tower?sprint=abc')->assertUnprocessable()->assertJsonValidationErrors('sprint');
    }

    public function test_dashboard_sync_returns_closed_sprints_frozen_at_their_end(): void
    {
        $this->prepareScenario();

        $rows = collect($this->postJson('/api/sync')->assertOk()->json('rows'));

        $sprint16 = $rows->where('Sprint', 16);
        $this->assertSame(['1', '2'], $sprint16->pluck('Id')->sort()->values()->all());
        $this->assertSame(['Em progresso'], $sprint16->pluck('Status')->unique()->values()->all());
        $this->assertSame('2026-09-27', $sprint16->first()['AsOf']);
    }

    public function test_control_tower_without_monday_configuration_explains_the_error(): void
    {
        $this->actingAs(User::factory()->create());

        $this->getJson('/api/control-tower')->assertUnprocessable()->assertJsonPath('error', 'Configure o quadro, token e coluna Sprint antes de atualizar.');
    }

    private function prepareScenario(): void
    {
        $this->travelTo('2026-10-07 15:00:00');
        $this->actingAs(User::factory()->create());
        $this->configureScenarioConnection();
        $this->seedScenarioHistory();
        Cache::put('monday-sync-1', $this->scenarioSnapshot(), 3600);
        Http::preventStrayRequests();
        Http::fake(['api.monday.com/v2' => Http::response(['data' => ['boards' => [['activity_logs' => []]]]])]);
    }
}
