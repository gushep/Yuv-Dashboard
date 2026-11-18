<?php

namespace Tests\Feature;

use App\Mcp\Tools\DashboardTool;
use App\Mcp\Tools\EpicsTool;
use App\Mcp\Tools\ScrumIndicatorsTool;
use App\Mcp\Tools\SprintMetricsTool;
use App\Mcp\Tools\TeamTool;
use App\Models\MondayConnection;
use App\Models\User;
use App\Screen;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Laravel\Mcp\Server;
use Tests\TestCase;

class McpKpiToolsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Sprint 6 em andamento; as sprints 1 a 5 já encerraram.
        $this->travelTo('2026-10-21 10:00:00');
        $connection = MondayConnection::current();
        $connection->board_id = '123';
        $connection->token = 'token-secreto';
        $connection->column_map = ['Sprint' => 'sprint', 'Status' => 'status'];
        $connection->save();
        Cache::put('monday-sync-1', $this->snapshot(), 3600);
        Http::preventStrayRequests();
    }

    public function test_dashboard_returns_the_indicators_of_all_sprints_without_the_card_lists(): void
    {
        KpiToolsServer::actingAs($this->user(Screen::Dashboard))->tool(DashboardTool::class)->assertOk()
            ->assertSee(['"averagePerSprint":2', '"tot":12', '"done":9', '"conclusao":75'])
            ->assertDontSee('"lists"');
    }

    public function test_sprint_metrics_opens_the_running_sprint_and_lists_cards_as_id_name_and_sprint(): void
    {
        $user = $this->user(Screen::Sprints);

        KpiToolsServer::actingAs($user)->tool(SprintMetricsTool::class)->assertOk()
            ->assertSee(['"sprint":6', '"previousSprint":5', '"tot":2', '"conclusao":50', '{"id":"61","nome":"Card 61","sprint":6}'])
            ->assertDontSee('"Responsavel"');
        KpiToolsServer::actingAs($user)->tool(SprintMetricsTool::class, ['sprint' => 99])
            ->assertHasErrors(['A sprint 99 não tem cards no Metrics. Sprints disponíveis: 1, 2, 3, 4, 5, 6.']);
    }

    public function test_epics_returns_tasks_and_hours_per_epic(): void
    {
        KpiToolsServer::actingAs($this->user(Screen::Epics))->tool(EpicsTool::class)->assertOk()
            ->assertSee(['"epic":"Mapa"', '"tasks":2', '"items":2']);
    }

    public function test_team_resolves_the_person_name_and_rejects_an_unknown_person(): void
    {
        $user = $this->user(Screen::Team);

        KpiToolsServer::actingAs($user)->tool(TeamTool::class, ['person' => 'ana souza', 'sprint' => 6])->assertOk()
            ->assertSee(['"dev":"Ana Souza"', '"sprint":6', '"conclusao":100']);
        KpiToolsServer::actingAs($user)->tool(TeamTool::class)->assertOk()->assertSee(['"name":"Ana Souza"', '"name":"Bia Lima"']);
        KpiToolsServer::actingAs($user)->tool(TeamTool::class, ['person' => 'Carlos'])
            ->assertHasErrors(['Pessoa "Carlos" não encontrada no time. Pessoas disponíveis: Ana Souza, Bia Lima.']);
    }

    public function test_scrum_indicators_use_the_default_periods_of_the_screen(): void
    {
        KpiToolsServer::actingAs(User::factory()->scrumMaster()->withScreens(Screen::ScrumIndicators)->create())->tool(ScrumIndicatorsTool::class)->assertOk()
            ->assertSee(['"beforeFrom":1', '"beforeTo":2', '"afterFrom":3', '"afterTo":5', '"label":"Sprints 1 a 2 (2)"', '"label":"Sprints 3 a 5 (3)"']);
    }

    public function test_scrum_indicators_require_the_four_period_limits_together(): void
    {
        KpiToolsServer::actingAs(User::factory()->scrumMaster()->withScreens(Screen::ScrumIndicators)->create())
            ->tool(ScrumIndicatorsTool::class, ['before_from' => 1, 'before_to' => 2])
            ->assertHasErrors(['Informe os quatro limites dos períodos (before_from, before_to, after_from e after_to) ou nenhum, para usar o padrão da tela.']);
    }

    public function test_scrum_indicators_are_only_for_the_scrum_master(): void
    {
        KpiToolsServer::actingAs(User::factory()->withScreens(Screen::ScrumIndicators)->create())->tool(ScrumIndicatorsTool::class)
            ->assertHasErrors();
    }

    public function test_each_tool_refuses_who_does_not_have_its_screen(): void
    {
        $user = $this->user(Screen::Burndown);

        KpiToolsServer::actingAs($user)->tool(DashboardTool::class)->assertHasErrors();
        KpiToolsServer::actingAs($user)->tool(SprintMetricsTool::class)->assertHasErrors();
        KpiToolsServer::actingAs($user)->tool(EpicsTool::class)->assertHasErrors();
        KpiToolsServer::actingAs($user)->tool(TeamTool::class)->assertHasErrors();
        KpiToolsServer::actingAs($user)->tools()->assertNotRegistered([DashboardTool::class, SprintMetricsTool::class, EpicsTool::class, TeamTool::class]);
    }

    private function user(Screen $screen): User
    {
        return User::factory()->withScreens($screen)->create();
    }

    /**
     * Seis sprints de duas semanas; 12 cards no escopo (9 implementados) e um cancelado, fora da conta.
     *
     * @return array<string, mixed>
     */
    private function snapshot(): array
    {
        $row = fn (string $id, int $sprint, string $status, string $owner = 'Ana Souza', array $extra = []): array => [
            'Id' => $id, 'Url' => "https://monday.com/boards/123/pulses/{$id}", 'Nome' => "Card {$id}", 'Responsavel' => $owner,
            'Status' => $status, 'Prioridade' => 'Média', 'Tipo' => 'Melhoria', 'Sprint' => $sprint, 'Epico' => '', 'Planejada' => 'Planejada',
            'Retrabalho' => 0, 'BugProducao' => 0, 'ValorAgregado' => 0, 'EstH' => 4.0, 'RealH' => 3.0, 'RemainingH' => null, 'Impedimento' => '', 'Modulo' => '',
            ...$extra,
        ];
        $starts = [1 => '2026-08-03', '2026-08-17', '2026-08-31', '2026-09-14', '2026-09-28', '2026-10-12'];

        return [
            'boardName' => 'Tarefas', 'fetched' => 13, 'skipped' => 0, 'unassignedSupport' => [], 'syncedAt' => '2026-10-21T09:00:00-03:00',
            'rows' => [
                $row('11', 1, 'Implementado'), $row('12', 1, 'Implementado'), $row('13', 1, 'Em progresso', 'Bia Lima'),
                $row('21', 2, 'Implementado', 'Ana Souza', ['Epico' => 'Mapa']), $row('22', 2, 'Implementado', 'Bia Lima', ['Epico' => 'Mapa']),
                $row('31', 3, 'Implementado'),
                $row('41', 4, 'Implementado'), $row('42', 4, 'Implementado', 'Bia Lima'), $row('43', 4, 'Cancelado'),
                $row('51', 5, 'Implementado'), $row('52', 5, 'Em progresso', 'Bia Lima'),
                $row('61', 6, 'Implementado'), $row('62', 6, 'Em progresso', 'Bia Lima'),
            ],
            'sprints' => array_map(fn (int $number, string $start): array => [
                'number' => $number, 'name' => "Sprint {$number}", 'start' => $start,
                'end' => date('Y-m-d', strtotime("{$start} +11 days")), 'source' => 'Cronograma do sprint no Monday',
            ], array_keys($starts), $starts),
        ];
    }
}

/**
 * Servidor só com as ferramentas testadas: o registro no MetricsServer acontece na integração do épico.
 */
class KpiToolsServer extends Server
{
    protected array $tools = [DashboardTool::class, SprintMetricsTool::class, EpicsTool::class, TeamTool::class, ScrumIndicatorsTool::class];
}
