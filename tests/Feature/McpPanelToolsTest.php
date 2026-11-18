<?php

namespace Tests\Feature;

use App\Mcp\Tools\FirefighterPanelTool;
use App\Mcp\Tools\ForecastTool;
use App\Mcp\Tools\SprintPanelTool;
use App\Models\MondayConnection;
use App\Models\User;
use App\Screen;
use App\Services\MondaySnapshotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Laravel\Mcp\Server;
use Tests\TestCase;

class McpPanelToolsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-11-18 12:00:00');
        MondayConnection::current()->update(['board_id' => '123', 'token' => 'token-secreto', 'column_map' => ['Sprint' => 'sprint']]);
        $card = fn (array $values): array => [
            'Id' => '', 'Nome' => '', 'Sprint' => 20, 'Status' => 'Backlog', 'Responsavel' => '', 'Tipo' => 'Melhoria', 'Prioridade' => 'Média',
            'EstH' => 0, 'RealH' => 0, 'Planejada' => 'Planejada', 'Descricao' => 'descrição longa que não deve sair', ...$values,
        ];
        Cache::forever(MondaySnapshotService::CACHE_KEY, [
            'syncedAt' => now()->toIso8601String(),
            'rows' => [
                $card(['Id' => '1', 'Nome' => 'Login', 'Status' => 'Em progresso', 'Responsavel' => 'Ana', 'Prioridade' => 'Alta', 'EstH' => 10, 'RealH' => 4]),
                $card(['Id' => '2', 'Nome' => 'Relatório', 'Status' => 'Implementado', 'Responsavel' => 'Ana', 'EstH' => 5, 'RealH' => 5]),
                $card(['Id' => '3', 'Nome' => 'Ticket do cliente', 'Tipo' => 'Suporte', 'Prioridade' => 'Crítica', 'Responsavel' => 'Bruno', 'EstH' => 2]),
                $card(['Id' => '4', 'Nome' => 'Bug sem dono', 'Tipo' => 'Bug', 'Prioridade' => 'Alta', 'EstH' => 3]),
            ],
            'sprints' => [['number' => 20, 'name' => 'Sprint 20', 'start' => '2026-11-16', 'end' => '2026-11-27']],
            'unassignedSupport' => [$card(['Id' => '5', 'Nome' => 'Ticket sem sprint', 'Tipo' => 'Suporte', 'Sprint' => null])],
        ]);
    }

    public function test_each_panel_tool_is_refused_without_its_screen(): void
    {
        $user = User::factory()->withScreens(Screen::Dashboard)->create();

        foreach ([ForecastTool::class, FirefighterPanelTool::class, SprintPanelTool::class] as $tool) {
            PanelToolsTestServer::actingAs($user)->tool($tool)->assertHasErrors();
        }
        PanelToolsTestServer::actingAs($user)->tools()->assertNotRegistered([ForecastTool::class, FirefighterPanelTool::class, SprintPanelTool::class]);
    }

    public function test_forecast_returns_the_remaining_hours_of_the_current_sprint_in_minutes(): void
    {
        $user = User::factory()->withScreens(Screen::Forecast)->create();

        PanelToolsTestServer::actingAs($user)->tool(ForecastTool::class)->assertOk()
            ->assertSee(['"sprint":20', '"name":"Ana"', '"known":360', '"remainingEffort":360', '"Nome":"Bug sem dono"', '"unit":"minutos"'])
            ->assertDontSee(['descrição longa', 'Relatório']);
    }

    public function test_firefighter_panel_lists_the_support_tickets_of_the_sprint_or_without_sprint(): void
    {
        $user = User::factory()->withScreens(Screen::Firefighter)->create();

        PanelToolsTestServer::actingAs($user)->tool(FirefighterPanelTool::class)->assertOk()
            ->assertSee(['"sprint":20', '"counts":{"backlog":1,"progress":0,"test":0,"done":0}', '"Nome":"Ticket do cliente"', '"total":1'])
            ->assertDontSee(['Login', 'descrição longa']);
        PanelToolsTestServer::actingAs($user)->tool(FirefighterPanelTool::class, ['sprint' => 'unassigned'])->assertOk()
            ->assertSee(['"sprint":"unassigned"', '"Nome":"Ticket sem sprint"']);
        PanelToolsTestServer::actingAs($user)->tool(FirefighterPanelTool::class, ['sprint' => 'qualquer'])->assertHasErrors(['Informe o número da sprint, "all" ou "unassigned".']);
    }

    public function test_sprint_panel_lists_the_sprint_cards_without_support_tickets(): void
    {
        $user = User::factory()->withScreens(Screen::SprintPanel)->create();

        PanelToolsTestServer::actingAs($user)->tool(SprintPanelTool::class, ['sprint' => 20])->assertOk()
            ->assertSee(['"sprint":20', '"counts":{"backlog":1,"progress":1,"test":0,"done":1}', '"total":3', '"Nome":"Login"'])
            ->assertDontSee(['Ticket do cliente', 'descrição longa', 'token-secreto']);
    }
}

/**
 * Servidor só para os testes: as ferramentas dos painéis ainda não estão registradas no MetricsServer.
 */
class PanelToolsTestServer extends Server
{
    protected array $tools = [ForecastTool::class, FirefighterPanelTool::class, SprintPanelTool::class];
}
