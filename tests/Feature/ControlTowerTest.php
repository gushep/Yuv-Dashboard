<?php

namespace Tests\Feature;

use App\Models\SprintCommitment;
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

    public function test_control_tower_and_commitments_require_login(): void
    {
        $this->getJson('/api/control-tower')->assertUnauthorized();
        $this->postJson('/api/commitments', ['sprint' => 17, 'itemId' => '1', 'committed' => true])->assertUnauthorized();
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
            ->assertJsonCount(8, 'burnup');

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

    public function test_marking_a_card_as_commitment_changes_the_commitment_totals(): void
    {
        $this->prepareScenario();

        $this->postJson('/api/commitments', ['sprint' => 17, 'itemId' => '7', 'committed' => true])
            ->assertOk()->assertJsonPath('commitments.7', true);
        $this->postJson('/api/commitments', ['sprint' => 17, 'itemId' => '4', 'committed' => false])->assertOk();

        $this->getJson('/api/control-tower')->assertJsonPath('totals.committed', 3)->assertJsonPath('totals.committedDone', 1);
        $this->assertEquals(['4' => false, '7' => true], SprintCommitment::forSprint(17));
    }

    public function test_invalid_commitment_is_rejected(): void
    {
        $this->actingAs(User::factory()->create());

        $this->postJson('/api/commitments', ['sprint' => 0, 'itemId' => 'abc', 'committed' => 'talvez'])
            ->assertUnprocessable()->assertJsonValidationErrors(['sprint', 'itemId', 'committed']);
        $this->assertSame(0, SprintCommitment::query()->count());
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
