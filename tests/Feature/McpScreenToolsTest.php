<?php

namespace Tests\Feature;

use App\Mcp\Tools\BurndownTool;
use App\Mcp\Tools\CardDetailsTool;
use App\Mcp\Tools\CardsTool;
use App\Mcp\Tools\ControlTowerTool;
use App\Mcp\Tools\DailyActionsTool;
use App\Mcp\Tools\PlanningTool;
use App\Mcp\Tools\ReleaseTool;
use App\Mcp\Tools\SprintRetrospectiveTool;
use App\Mcp\Tools\SprintReviewTool;
use App\Models\DailyAction;
use App\Models\DailyScrum;
use App\Models\PlanningRound;
use App\Models\PlanningSession;
use App\Models\Retrospective;
use App\Models\RetrospectiveAction;
use App\Models\SprintReview;
use App\Models\User;
use App\PlanningStatus;
use App\Screen;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Testing\TestResponse;
use Tests\Concerns\SprintHistoryScenario;
use Tests\TestCase;

class McpScreenToolsTest extends TestCase
{
    use RefreshDatabase;
    use SprintHistoryScenario;

    private const TOOLS = [
        ControlTowerTool::class, BurndownTool::class, ReleaseTool::class, PlanningTool::class, DailyActionsTool::class,
        SprintReviewTool::class, SprintRetrospectiveTool::class, CardsTool::class, CardDetailsTool::class,
    ];

    /** Itens que o Monday devolve na consulta de detalhe de card. */
    private array $mondayItems = [];

    protected function setUp(): void
    {
        parent::setUp();
        // Quarta, 07/10/2026: sprint 17 em andamento (28/09 a 09/10), sprint 16 encerrada.
        $this->travelTo('2026-10-07 15:00:00');
        $this->configureScenarioConnection();
        $this->seedScenarioHistory();
        Cache::put('monday-sync-1', $this->scenarioSnapshot(), 3600);
        Http::preventStrayRequests();
        Http::fake(['api.monday.com/v2' => fn (HttpRequest $request) => str_contains((string) ($request->data()['query'] ?? ''), 'activity_logs')
            ? Http::response(['data' => ['boards' => [['activity_logs' => []]]]])
            : Http::response(['data' => ['items' => $this->mondayItems]])]);
    }

    public function test_each_tool_is_hidden_from_who_does_not_have_its_screen(): void
    {
        $dashboardOnly = User::factory()->withScreens(Screen::Dashboard)->create();
        McpScreenToolsTestServer::actingAs($dashboardOnly)->tools()->assertNotRegistered(self::TOOLS);
        McpScreenToolsTestServer::actingAs($dashboardOnly)->tool(ControlTowerTool::class)->assertHasErrors();

        $towerOnly = User::factory()->withScreens(Screen::ControlTower)->create();
        McpScreenToolsTestServer::actingAs($towerOnly)->tools()
            ->assertRegistered(ControlTowerTool::class)
            ->assertNotRegistered(array_values(array_diff(self::TOOLS, [ControlTowerTool::class])));
    }

    public function test_control_tower_returns_the_screen_data_for_the_current_sprint(): void
    {
        $tower = $this->toolData($this->callTool(ControlTowerTool::class, Screen::ControlTower));

        $this->assertSame(17, $tower['sprint']);
        $this->assertSame(5, $tower['totals']['scope']);
        $this->assertSame(1, $tower['totals']['done']);
        $this->assertSame('red', $tower['status']);
        $this->assertStringNotContainsString('token-secreto', json_encode($tower));
    }

    public function test_burndown_accepts_the_sprint_and_validates_it(): void
    {
        $this->assertSame(16, $this->toolData($this->callTool(BurndownTool::class, Screen::Burndown, ['sprint' => 16]))['sprint']);
        $this->assertSame(17, $this->toolData($this->callTool(BurndownTool::class, Screen::Burndown))['sprint']);
        $this->callTool(BurndownTool::class, Screen::Burndown, ['sprint' => 'abc'])->assertHasErrors();
    }

    public function test_release_lists_the_implemented_cards_of_the_sprint(): void
    {
        $release = $this->toolData($this->callTool(ReleaseTool::class, Screen::Release, ['sprint' => 17]));

        $this->assertSame(1, $release['total']);
        $this->assertSame('Card 1', $release['groups'][0]['items'][0]['title']);
    }

    public function test_planning_lists_the_history_and_shows_the_rounds_of_a_sprint_without_joining_the_table(): void
    {
        $session = PlanningSession::factory()->closed()->create(['sprint' => 17, 'sprint_name' => 'Sprint 17', 'goal' => 'Entregar o login']);
        PlanningRound::factory()->for($session, 'session')->create(['item_id' => '4', 'item_name' => 'Card 4', 'status' => PlanningStatus::Closed, 'final_estimate' => 5, 'closed_at' => now()]);
        $open = PlanningSession::factory()->create(['sprint' => 18, 'sprint_name' => 'Sprint 18']);
        $user = User::factory()->withScreens(Screen::Planning)->create();

        $history = $this->toolData($this->callTool(PlanningTool::class, Screen::Planning, [], $user));
        $this->assertSame([17, 16], array_column($history['sprints'], 'number'));
        $this->assertSame('Entregar o login', $history['sprints'][0]['planning']['goal']);
        $this->assertArrayNotHasKey('tasks', $history['sprints'][0]);
        $limited = $this->toolData($this->callTool(PlanningTool::class, Screen::Planning, ['limit' => 1, 'include_cards' => true], $user));
        $this->assertSame([17], array_column($limited['sprints'], 'number'));
        $this->assertArrayHasKey('tasks', $limited['sprints'][0]);

        $sprint = $this->toolData($this->callTool(PlanningTool::class, Screen::Planning, ['sprint' => 17], $user));
        $this->assertStringContainsString('Card 4', json_encode($sprint['rounds'], JSON_UNESCAPED_UNICODE));
        $this->assertDatabaseMissing('planning_participants', ['planning_session_id' => $open->id, 'user_id' => $user->id]);
    }

    public function test_daily_actions_show_the_sprint_dailies_and_open_retro_actions_without_counting_presence(): void
    {
        $daily = DailyScrum::factory()->create(['sprint' => 17, 'date' => '2026-10-06']);
        DailyAction::factory()->for($daily, 'daily')->create(['description' => 'Liberar acesso ao ambiente de QA']);
        DailyScrum::factory()->create(['sprint' => 16, 'date' => '2026-09-22']);
        RetrospectiveAction::factory()->create(['description' => 'Revisar critérios de aceite na planning']);

        $result = $this->toolData($this->callTool(DailyActionsTool::class, Screen::DailyScrum));

        $this->assertSame(17, $result['sprint']);
        $this->assertCount(1, $result['dailies']);
        $this->assertSame('Liberar acesso ao ambiente de QA', $result['dailies'][0]['actions'][0]['description']);
        $this->assertSame('Revisar critérios de aceite na planning', $result['retroActions'][0]['description']);
        $this->assertDatabaseMissing('daily_scrums', ['sprint' => 17, 'date' => '2026-10-07']);
        $this->assertDatabaseCount('daily_participants', 0);
    }

    public function test_sprint_review_shows_the_overview_and_the_review_of_a_sprint(): void
    {
        SprintReview::factory()->closed([['id' => '2', 'name' => 'Card 2']])->create(['sprint' => 16, 'sprint_name' => 'Sprint 16', 'goal_result' => 'yes']);

        $overview = $this->toolData($this->callTool(SprintReviewTool::class, Screen::SprintReview));
        $this->assertSame(1, $overview['kpis']['reviews']);
        $this->assertSame(1, $overview['kpis']['goalHit']);

        $review = $this->toolData($this->callTool(SprintReviewTool::class, Screen::SprintReview, ['sprint' => 16]));
        $this->assertSame(SprintReview::CLOSED, $review['review']['status']);
        $this->assertSame('Card 2', $review['review']['items'][0]['name']);
    }

    public function test_sprint_retrospective_shows_the_actions_and_the_retro_of_a_sprint(): void
    {
        $retro = Retrospective::factory()->closed()->create(['sprint' => 16, 'sprint_name' => 'Sprint 16']);
        RetrospectiveAction::factory()->create(['retrospective_id' => $retro->id, 'description' => 'Pareamento nas tarefas de QA']);

        $overview = $this->toolData($this->callTool(SprintRetrospectiveTool::class, Screen::SprintRetrospective));
        $this->assertSame('Pareamento nas tarefas de QA', $overview['actions'][0]['description']);

        $sprint = $this->toolData($this->callTool(SprintRetrospectiveTool::class, Screen::SprintRetrospective, ['sprint' => 16]));
        $this->assertSame(Retrospective::CLOSED, $sprint['retro']['status']);
    }

    public function test_cards_lists_the_sprint_cards_with_filters(): void
    {
        $all = $this->toolData($this->callTool(CardsTool::class, Screen::RawData));
        $this->assertSame(17, $all['sprint']);
        $this->assertSame(5, $all['total']);
        $this->assertSame(['1', '4', '5', '6', '7'], array_column($all['cards'], 'id'));

        $support = $this->toolData($this->callTool(CardsTool::class, Screen::RawData, ['type' => 'SUPORTE']));
        $this->assertSame(['6'], array_column($support['cards'], 'id'));
        $this->assertEquals(6, $support['cards'][0]['realHours']);

        $stopped = $this->toolData($this->callTool(CardsTool::class, Screen::RawData, ['sprint' => 17, 'status' => 'parado', 'responsible' => 'ana']));
        $this->assertSame(['5'], array_column($stopped['cards'], 'id'));
    }

    public function test_card_details_reads_the_card_from_monday_and_validates_the_id(): void
    {
        $this->mondayItems = [[
            'id' => '55', 'name' => 'Corrigir login', 'url' => 'https://monday.com/boards/123/pulses/55',
            'created_at' => '2026-10-01T12:00:00Z', 'updated_at' => '2026-10-05T12:00:00Z', 'creator' => ['name' => 'Bruno Lima'],
            'group' => ['title' => 'Sprint 17', 'color' => '#579bfc'], 'board' => ['id' => '123', 'name' => 'Tarefas', 'columns' => []],
            'column_values' => [['id' => 'status', 'text' => 'Em progresso', 'type' => 'status', 'column' => ['title' => 'Status']]],
            'subitems' => [], 'updates' => [],
        ]];

        $card = $this->toolData($this->callTool(CardDetailsTool::class, Screen::RawData, ['item' => '55', 'include_activity' => true]));
        $this->assertSame('Corrigir login', $card['name']);
        $this->assertSame([], $card['activity']);

        $this->callTool(CardDetailsTool::class, Screen::RawData, ['item' => '55; drop'])->assertHasErrors(['O id do card tem só números.']);
    }

    /**
     * @param  class-string  $tool
     * @param  array<string, mixed>  $arguments
     */
    private function callTool(string $tool, Screen $screen, array $arguments = [], ?User $user = null): TestResponse
    {
        return McpScreenToolsTestServer::actingAs($user ?? User::factory()->withScreens($screen)->create())->tool($tool, $arguments);
    }

    /**
     * JSON devolvido pela ferramenta.
     *
     * @return array<string, mixed>
     */
    private function toolData(TestResponse $response): array
    {
        $response->assertHasNoErrors();
        $text = (fn (): array => $this->content())->call($response)[0] ?? '';

        return json_decode($text, true, flags: JSON_THROW_ON_ERROR);
    }
}

/**
 * Servidor de teste com as ferramentas das telas (o MetricsServer as registra separadamente).
 */
class McpScreenToolsTestServer extends Server
{
    protected array $tools = [
        ControlTowerTool::class, BurndownTool::class, ReleaseTool::class, PlanningTool::class, DailyActionsTool::class,
        SprintReviewTool::class, SprintRetrospectiveTool::class, CardsTool::class, CardDetailsTool::class,
    ];
}
